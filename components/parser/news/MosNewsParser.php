<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use linslin\yii2\curl\Curl;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class MosNewsParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public static function run(): array
    {
        $parser = new static(500000, 5);

        return $parser->parse(10, 10);
    }

    public function getSiteUrl(): string
    {
        return 'https://mos.news/';
    }

    protected function factoryCurl(): Curl
    {
        $curl = parent::factoryCurl();
        $curl = $curl->setHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');

        return $curl;
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 50): array
    {
        $previewList = [];
        $pageNumber = 1;
        while (count($previewList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/?PAGEN_1={$pageNumber}", $this->getSiteUrl());
            $pageNumber++;
            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $itemsXPath = '//div[@id="comp_"]';
            $previewNewsCrawler = $previewNewsCrawler->filterXPath($itemsXPath);
            $previewNewsCrawler->each(
                function (Crawler $newsPreview) use (&$previewList, $uriPreviewPage) {
                    $titleCrawler = $newsPreview->filterXPath('//div[contains(@class, "news-name")]/a');
                    $uri = UriResolver::resolve($titleCrawler->attr('href'), $uriPreviewPage);
                    $preview = $newsPreview->filterXPath('//div[contains(@class,"news-text")]')->text();
                    $previewList[] = new PreviewNewsDTO($uri, null, $titleCrawler->text(), $preview);
                }
            );
        }
        $previewList = array_slice($previewList, 0, $maxNewsCount);
        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $contentCrawler = $newsPageCrawler->filterXPath('//article[contains(@class,"article")]');
        $this->removeDomNodes($contentCrawler, '//*[contains(@class,"news-views")]');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//a[contains(@class,"article-img")]/img[1]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $this->getSiteUrl());
            $previewNewsDTO->setImage($image);
        }

        $publishedAtString = Text::trim($contentCrawler->filterXPath('//div[contains(@class, "article-date")]')->text());
        $this->removeDomNodes($contentCrawler, '//div[contains(@class, "article-date")]');
        $timezone = new DateTimeZone('Europe/Moscow');
        $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y H:i', $publishedAtString, $timezone);
        $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));
        $previewNewsDTO->setPublishedAt($publishedAtUTC);

        $contentCrawler = $contentCrawler->filter('.detail_text_container');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }
}
