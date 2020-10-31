<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
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

class FishnewsRuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://fishnews.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];

        $uriPreviewPage = UriResolver::resolve('/rss', $this->getSiteUrl());

        try {
            $previewNewsContent = $this->getPageContent($uriPreviewPage);
            $previewNewsCrawler = new Crawler($previewNewsContent);
        } catch (Throwable $exception) {
            if (count($previewNewsDTOList) < $minNewsCount) {
                throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
            }
        }

        $previewNewsCrawler = $previewNewsCrawler->filterXPath('//item');

        $previewNewsCrawler->each(function (Crawler $newsPreview) use (&$previewNewsDTOList, $maxNewsCount) {
            if (count($previewNewsDTOList) >= $maxNewsCount) {
                return;
            }

            $title = $newsPreview->filterXPath('//title')->text();
            $uri = $newsPreview->filterXPath('//link')->text();

            $publishedAtString = $newsPreview->filterXPath('//pubDate')->text();
            $publishedAt = DateTimeImmutable::createFromFormat(DATE_RFC1123, $publishedAtString);
            $publishedAtUTC = $publishedAt->setTimezone(new DateTimeZone('UTC'));

            $image = null;
            $imageCrawler = $newsPreview->filterXPath('//enclosure');
            if ($this->crawlerHasNodes($imageCrawler)) {
                $image = $imageCrawler->attr('url') ?: null;
            }

            $previewNewsDTOList[] = new PreviewNewsDTO($uri, $publishedAtUTC, $title, null, $image);
        });

        $previewNewsDTOList = array_slice($previewNewsDTOList, 0, $maxNewsCount);

        return $previewNewsDTOList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = rawurldecode($previewNewsDTO->getUri());

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $contentCrawler = $newsPageCrawler->filter('article.news');
        if ($contentCrawler->count()) {
            $this->removeDomNodes($contentCrawler, '//time');
            $this->removeDomNodes($contentCrawler, '//h1[1]');
            $this->removeDomNodes($contentCrawler, '//strong[contains(text(),"Самые оперативные новости читайте в")]/parent::p/following-sibling::*');
            $this->removeDomNodes($contentCrawler, '//strong[contains(text(),"Больше новостей читайте в")]/parent::p/following-sibling::*');
            $this->removeDomNodes($contentCrawler, '//strong[contains(text(),"Самые оперативные новости читайте в")]/parent::p');
            $this->removeDomNodes($contentCrawler, '//strong[contains(text(),"Больше новостей читайте в")]/parent::p');
        } else {
            $previewNewsDTO->setTitle($newsPageCrawler->filter('head > title')->text());
            $contentCrawler = $newsPageCrawler->filterXPath('//*[contains(concat(" ",normalize-space(@class)," ")," content ")] | //div[contains(@class,"photos-list")]');
            $descriptionCrawler = $newsPageCrawler->filter('.anons');
            if ($this->crawlerHasNodes($descriptionCrawler)) {
                $description = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));
            }
        }

        $image = null;
        $mainImageCrawler = $newsPageCrawler->filterXPath('//a[contains(@class,"interviewee-photo")]/img[1]/parent::a');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('href');
            $this->removeDomNodes($contentCrawler, '//a[contains(@class,"interviewee-photo")]/img[1]/parent::a');
        } else {
            $mainImageCrawler = $newsPageCrawler->filterXPath('//a[contains(@class,"interviewee-photo")]/img[1]');
            if ($this->crawlerHasNodes($mainImageCrawler)) {
                $image = $mainImageCrawler->attr('src');
                $this->removeDomNodes($contentCrawler, '//a[contains(@class,"interviewee-photo")]');
            }
        }

        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $uri);
            $previewNewsDTO->setImage($this->encodeUri($image));
        }

        if (!$description) {
            $description = $this->getDescriptionFromContentText($contentCrawler);
        }

        if ($description && $description !== '') {
            $previewNewsDTO->setDescription($description);
        }

        $this->purifyNewsPostContent($contentCrawler);

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    protected function getImageLinkFromNode(DOMElement $node): string
    {
        $parentNode = $node->parentNode;
        if ($parentNode instanceof DOMElement && str_contains($parentNode->getAttribute('class'), 'photo-gallery')) {
            return $parentNode->getAttribute('href');
        }

        return $node->getAttribute('src');
    }

    private function getDescriptionFromContentText(Crawler $crawler): ?string
    {
        $descriptionCrawler = $crawler->filterXPath('//p[contains(@class,"anons")][1]');

        if ($this->crawlerHasNodes($descriptionCrawler)) {
            $descriptionText = Text::trim($this->normalizeSpaces($descriptionCrawler->text()));

            if ($descriptionText) {
                $this->removeDomNodes($crawler, '//p[contains(@class,"anons")][1]');
                return $descriptionText;
            }
        }

        return null;
    }
}
