<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class Region125Parser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://125region.ru';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewList = [];
        $url = "/feed/";
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
            $uri = $newsPreview->filterXPath('//link')->text();
            $publishedAtString = $newsPreview->filterXPath('//pubDate')->text();
            $preview = null;

            $publishedAt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s O', $publishedAtString);
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

        $contentCrawler = $newsPostCrawler->filterXPath('//div[contains(@class,"entry-content")]');
        $this->removeDomNodes($contentCrawler, '//p[@id="post-modified-info"]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"addtoany_content_top")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"code-block")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"addtoany_content_bottom")]');
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"addtoany_content_bottom")]/following-sibling::*');

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsItem);

        return $this->factoryNewsPost($previewNewsItem, $newsPostItemDTOList);
    }

    protected function getImageLinkFromNode(DOMElement $node): string
    {
          if ($node->hasAttribute('data-jg-srcset')) {
            $srcset = $node->getAttribute('data-jg-srcset');
            $parts = explode(', ',$srcset);
            $maxSize = null;
            $maxSizeSrc = null;
            foreach ($parts as $srcString){
                $delimiterPosition =mb_strrpos($srcString,' ');
                $size = (int) mb_substr($srcString,$delimiterPosition);
                if($maxSize < $size){
                    $maxSize = $size;
                    $maxSizeSrc= mb_substr($srcString,0,$delimiterPosition);
                }
            }

            if ($maxSizeSrc !== '' && $maxSizeSrc !== null) {
                return $maxSizeSrc;
            }
        }

        return $node->getAttribute('src');
    }
}