<?php

namespace app\components\parser\news;

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

class Krestyane34Parser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    public static function run(): array
    {
        $parser = new static();

        return $parser->parse(5, 50);
    }

    protected function getSiteUrl(): string
    {
        return 'https://krestyane34.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];

        try {
            $previewNewsContent = $this->getPageContent($this->getSiteUrl());
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
        }

        $previewXpath = '//a[contains(@href,"/novosti")]/parent::*/parent::*//div[contains(@class,"dayitems")]';
        $previewNewsCrawler = $previewNewsCrawler->filterXPath($previewXpath);
        if ($previewNewsCrawler->count() < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
            $titleCrawler = $newsPreview->filterXPath('//a');
            $uri = UriResolver::resolve($titleCrawler->attr('href'), $this->getSiteUrl());
            $uri = $this->encodeUri($uri);
            $publishedAtString = $newsPreview->filterXPath('//div[contains(@class,"latdate")]')->text();
            $preview = null;

            $timezone = new DateTimeZone('Europe/Volgograd');
            $publishedAt = DateTimeImmutable::createFromFormat('H:i d.m.Y \г.', $publishedAtString, $timezone);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $titleCrawler->text(), $preview);
        });

        if (count($previewList) < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $newsPostCrawler = $newsPageCrawler;

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[contains(@property,"og:image")]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null && $image !== '') {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"artcont")]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }

    protected function factoryCurl(): Curl
    {
        $curl = parent::factoryCurl();
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, false);

        return $curl;
    }
}