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

class ZvezdaGukovoRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://zvezdagukovo.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $url = "/feed";
        $uriPreviewPage = UriResolver::resolve($url, $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            preg_match_all('/<item>.*?<\/item>/ms', $previewNewsContent, $matches, PREG_SET_ORDER, 0);
        } catch (Throwable $exception) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
        }


        foreach ($matches as $item) {
            $itemText = $item[0];
            preg_match('/<title>(.*?)<\/title>/s', $itemText, $matches);
            $title = $matches[1];

            preg_match('/<link>(.*?)<\/link>/s', $itemText, $matches);
            $uri = $matches[1];

            preg_match('/<pubDate>(.*?)<\/pubDate>/s', $itemText, $matches);
            $timezone = new DateTimeZone('Europe/Moscow');
            $publishedAtString = mb_substr($matches[1],0,-6);
            $publishedAt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s', $publishedAtString,$timezone);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $preview = null;

            $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
        }

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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//div[contains(concat(" ",normalize-space(@class)," ")," entry-content ")]');

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//meta[@property="og:image"]')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('content');
        }
        if ($image !== null && $image !== '') {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }


        $contentCrawler = $newsPostCrawler;

        $this->removeDomNodes($contentCrawler, '//*[contains(translate(substring(text(), 0, 14), "ФОТО", "фото"), "фото")]
        | //*[contains(text(), "Источник:")]
        | //*[@class="wp-block-embed"]
        | //*[following-sibling::*[contains(text(), "Читайте также")]][last()]/following-sibling::*');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }
}