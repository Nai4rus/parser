<?php

namespace app\components\parser\news;

use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class SibiricaSuParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://sibirica.su/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];

        $uriPreviewPage = UriResolver::resolve('/feed/rss', $this->getSiteUrl());

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
        usort($previewNewsDTOList, function (PreviewNewsDTO $a, PreviewNewsDTO $b) {
            if ($a->getPublishedAt()->getTimestamp() === $b->getPublishedAt()->getTimestamp()) {
                return 0;
            }

            return $a->getPublishedAt()->getTimestamp() < $b->getPublishedAt()->getTimestamp() ? 1 : -1;
        });

        return $previewNewsDTOList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $description = $previewNewsDTO->getDescription();
        $uri = $previewNewsDTO->getUri();

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);

        $contentCrawler = $newsPageCrawler->filterXPath('//section[@itemprop="articleBody"]');
        if ($contentCrawler->filterXPath('//span[contains(text(),"Читать дальше…")]//ancestor::a')->count()) {
            $hrefReedMore = $contentCrawler->filterXPath('//span[contains(text(),"Читать дальше…")]//ancestor::a')->attr('href');
            $uri = UriResolver::resolve($hrefReedMore, $this->getSiteUrl());
        }
        $newsPostItemDTOList = [];
        while (isset($uri)) {
            $newsPage = $this->getPageContent($uri);

            $newsPageCrawler = new Crawler($newsPage);
            $contentCrawler = $newsPageCrawler->filterXPath('//section[@itemprop="articleBody"]');

            unset($uri);
            if ($contentCrawler->filterXPath('//a[@title="Вперед"]')->count()) {
                $uri = UriResolver::resolve($contentCrawler->filterXPath('//a[@title="Вперед"]')->attr('href'), $this->getSiteUrl());
            }

            $this->removeDomNodes($contentCrawler, '//*[contains(@class,"pagination")]');
            $this->removeDomNodes($contentCrawler, '//div[contains(@class,"pagenavcounter")]');
            $this->removeDomNodes($contentCrawler, '//ul[contains(@class,"relateditems")]');

            $image = null;

            $mainImageCrawler = $contentCrawler->filterXPath('//img[1]');
            if ($this->crawlerHasNodes($mainImageCrawler)) {
                $image = $mainImageCrawler->attr('src');
                $this->removeDomNodes($contentCrawler, '//img[1]');
            }

            if ($image !== null && $image !== '') {
                $image = $this->encodeUri(UriResolver::resolve($image, $this->getSiteUrl()));
                $previewNewsDTO->setImage($image);
            }

            if ($description && $description !== '') {
                $previewNewsDTO->setDescription($description);
            }

            $this->purifyNewsPostContent($contentCrawler);

            $newsPostItemDTOList = array_merge($newsPostItemDTOList ?? [], $this->parseNewsPostContent($contentCrawler, $previewNewsDTO));
        }

        $newsPost = $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);

        /** @var NewsPostItem $item */
        foreach ($newsPost->items as $key => $item) {
            if (str_contains($item->text ?: '', 'var player1 = new Clappr.Player') || str_contains($item->text ?: '', 'rel="nofollow"')) {
                unset($newsPost->items[$key]);
            }
        }

        $newsPost->items = array_values($newsPost->items);

        return $newsPost;
    }
}