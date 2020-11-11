<?php

namespace app\components\parser\news;

use app\components\Helper;
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

class RodStoronatarRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://rod-storonatar.ru';
    }

    protected function factoryCurl(): Curl
    {
        $curl = Helper::getCurl();
        $curl->setOption(CURLOPT_ENCODING, "gzip");
        $curl->setHeader('Cookie', 'beget=begetok');

        return $curl;
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $url = "/feed";
        $uriPreviewPage = UriResolver::resolve($url, $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//item');
        if ($previewNewsCrawler->count() < $minNewsCount) {
            throw new RuntimeException('Не удалось получить достаточное кол-во новостей');
        }

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewList, $url) {
            $title = $newsPreview->filterXPath('//title')->text();
            $html = $newsPreview->html();
            preg_match('/<link>(.+?)(<|$)/m', $html, $uriMatch);
            $uri = $uriMatch[1];
            $publishedAtString = $newsPreview->filterXPath('//pubdate')->text();
            $preview = null;

            $publishedAtString = mb_substr($publishedAtString, 0, -6);
            $timezone = new DateTimeZone('Europe/Moscow');
            $publishedAt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s', $publishedAtString, $timezone);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $previewList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, $preview);
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
        $newsPostCrawler = $newsPageCrawler->filterXPath('//article');

        try {
            $times = $newsPostCrawler->filterXPath('//time')->each(function (Crawler $node) {
                return $node->text();
            });
            $publishedAtString = implode(' ', $times);
            $timezone = new DateTimeZone('Europe/Moscow');
            $publishedAt = DateTimeImmutable::createFromFormat('d.m.Y H:i', $publishedAtString, $timezone);
            $previewNewsItem->setPublishedAt($publishedAt->setTimezone(new DateTimeZone('UTC')));
        } catch (\Throwable $th) {
        }

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//*[@class="ymnews-single-header-thumbnail"]//img')->first();
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
        }
        if ($image !== null && $image !== '') {
            $previewNewsItem->setImage(UriResolver::resolve($image, $uri));
        }

        $descriptionCrawler = $newsPostCrawler->filterXPath('//p[1][child::strong]');
        if ($this->crawlerHasNodes($descriptionCrawler) && $descriptionCrawler->text() !== '') {
            $previewNewsItem->setDescription($descriptionCrawler->text());
            $this->removeDomNodes($newsPostCrawler, '//p[1][child::strong]');
        }

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"ymnews-single-content")]');

        $this->removeDomNodes($contentCrawler, '//*[@class="wp-block-embed"]');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }
}