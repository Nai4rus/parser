<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class Rayon72RuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;
    public const SITE_URL = 'https://rayon72.ru';

    protected function getSiteUrl(): string
    {
        return 'https://rayon72.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];

        $uriPreviewPage = UriResolver::resolve('/rss', $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);

            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//item');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList) {
            $uri = $newsPreview->filterXPath('//link')->text();
            $title = $newsPreview->filterXPath('//title')->text();

            $publishedAtString = $newsPreview->filterXPath('//pubDate | //pubdate')->text();

            $publishedAt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s O', $publishedAtString);
            if($publishedAt === false){
                $publishedAt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s.u O', $publishedAtString);
            }
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, null);
        });

        $previewList = array_slice($previewList, 0, $maxNewsCount);

        return $previewList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsItem): NewsPost
    {
        $uri = $previewNewsItem->getUri();
        $image = null;


        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);

        $newsPostCrawler = $newsPageCrawler->filterXPath('//*[@class="cp-post-tools"]/following-sibling::*');

        $imageNode = $newsPageCrawler->filterXPath('//meta[@property="og:image"]');
        if ($this->crawlerHasNodes($imageNode)) {
            $src = $imageNode->attr('content');
            $image = $src ? UriResolver::resolve($src, $this->getSiteUrl()) : null;
            $previewNewsItem->setImage($image);
        }

        $this->removeDomNodes($newsPostCrawler, '//*[contains(translate(substring(text(), 0, 14), "ФОТО", "фото"), "фото")]
        | //*[@class="widget_iframe"]');

        $contentCrawler = $newsPostCrawler;

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }
}