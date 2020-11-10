<?php

namespace app\components\parser\news;

use app\components\helper\metallizzer\Text;
use app\components\helper\nai4rus\AbstractBaseParser;
use app\components\helper\nai4rus\PreviewNewsDTO;
use app\components\parser\NewsPost;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

class CiarfsParser extends AbstractBaseParser
{
    public const USER_ID = 2;
    public const FEED_ID = 2;

    protected function getSiteUrl(): string
    {
        return 'https://ciarf.ru/';
    }

    protected function getPreviewNewsDTOList(int $minNewsCount = 10, int $maxNewsCount = 100): array
    {
        $previewNewsDTOList = [];
        $pageNumber = 1;

        while (count($previewNewsDTOList) < $maxNewsCount) {
            $uriPreviewPage = UriResolver::resolve("/news/?page={$pageNumber}", $this->getSiteUrl());
            $pageNumber++;

            try {
                $previewNewsContent = $this->getPageContent($uriPreviewPage);
                $previewNewsCrawler = new Crawler($previewNewsContent);
            } catch (Throwable $exception) {
                if (count($previewNewsDTOList) < $minNewsCount) {
                    throw new RuntimeException('Не удалось получить достаточное кол-во новостей', null, $exception);
                }
                break;
            }

            $previewNewsCrawler = $previewNewsCrawler->filter('.news-list__item');

            $previewNewsCrawler->each(
                function (Crawler $newsPreview) use (&$previewNewsDTOList) {
                    $title = $newsPreview->filter('.news-list__title')->text();
                    $uri = UriResolver::resolve($newsPreview->attr('href'), $this->getSiteUrl());
                    $publishedAt = $this->getPublishedAt($newsPreview);

                    $previewNewsDTOList[] = new PreviewNewsDTO($uri, $publishedAt, $title);
                }
            );
        }

        $previewNewsDTOList = array_slice($previewNewsDTOList, 0, $maxNewsCount);
        return $previewNewsDTOList;
    }

    protected function parseNewsPage(PreviewNewsDTO $previewNewsDTO): NewsPost
    {
        $uri = $previewNewsDTO->getUri();
        $image = null;

        $newsPage = $this->getPageContent($uri);

        $newsPageCrawler = new Crawler($newsPage);
        $contentCrawler = $newsPageCrawler->filter('.detail-news__text');

        $mainImageCrawler = $newsPageCrawler->filterXPath('//div[contains(@class,"detail-news__image-box")]//img[1]');
        if ($this->crawlerHasNodes($mainImageCrawler)) {
            $image = $mainImageCrawler->attr('src');
            $this->removeDomNodes($contentCrawler, '//div[contains(@class,"detail-news__image-box")]');
        }
        if ($image !== null && $image !== '') {
            $image = UriResolver::resolve($image, $this->getSiteUrl());
            $previewNewsDTO->setImage($image);
        }

        $this->purifyNewsPostContent($contentCrawler);
        $this->removeDomNodes($contentCrawler, '//div[contains(@class,"detail-news__text__tags")]');

        $newsPostItemDTOList = $this->parseNewsPostContent($contentCrawler, $previewNewsDTO);

        return $this->factoryNewsPost($previewNewsDTO, $newsPostItemDTOList);
    }

    private function getPublishedAt(Crawler $crawler): ?DateTimeImmutable
    {
        $months = [
            1 => 'янв',
            2 => 'фев',
            3 => 'мар',
            4 => 'апр',
            5 => 'май',
            6 => 'июн',
            7 => 'июл',
            8 => 'авг',
            9 => 'сен',
            10 => 'окт',
            11 => 'ноя',
            12 => 'дек',
        ];

        $publishedAtCrawler = $crawler->filter('.news-list__date-box');
        $this->removeDomNodes($publishedAtCrawler, '//*[contains(@class,"news-list__read-more")]');
        $publishedAtString = Text::trim($this->normalizeSpaces($publishedAtCrawler->text()));

        $publishedAtString = str_replace($months, array_keys($months), $publishedAtString);
        $publishedAt = DateTimeImmutable::createFromFormat('H:i d m', $publishedAtString, new DateTimeZone('Europe/Ulyanovsk'));

        return $publishedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
