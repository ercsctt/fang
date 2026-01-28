<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Ocado;

use App\Crawler\Extractors\BaseProductDetailsExtractor;
use App\Crawler\Services\CategoryExtractor;
use Symfony\Component\DomCrawler\Crawler;

class OcadoProductDetailsExtractor extends BaseProductDetailsExtractor
{
    public function __construct(
        private readonly CategoryExtractor $categoryExtractor,
    ) {}

    public function canHandle(string $url): bool
    {
        if (! str_contains($url, 'ocado.com')) {
            return false;
        }

        return (bool) preg_match('/\/products\/[a-z0-9-]+-\d+$/i', $url);
    }

    protected function getRetailerSlug(): string
    {
        return 'ocado';
    }

    protected function shouldExtract(Crawler $crawler, string $html, string $url): bool
    {
        if ($this->isBlockedPage($crawler, $html)) {
            $this->logWarning("Blocked/CAPTCHA page detected at {$url}");

            return false;
        }

        return true;
    }

    protected function getTitleSelectors(): array
    {
        return [
            'h1[data-testid="product-title"]',
            'h1.fop-title',
            '.product-title h1',
            '.productTitle h1',
            '[data-test="product-title"]',
            'h1',
        ];
    }

    protected function getPriceSelectors(): array
    {
        return [
            '[data-testid="product-price"]',
            '.fop-price',
            '.product-price',
            '.price-current',
            '[data-test="product-price"]',
            '.price',
        ];
    }

    protected function getOriginalPriceSelectors(): array
    {
        return [
            '.was-price',
            '.price-was',
            '.original-price',
            '.price-rrp',
            '[data-original-price]',
            's.price',
            'del.price',
            '.strikethrough-price',
            '.fop-price-was',
        ];
    }

    protected function getDescriptionSelectors(): array
    {
        return [
            '[data-testid="product-description"]',
            '.product-description',
            '.fop-description',
            '.description-content',
            '#product-description',
            '.product-info-description',
        ];
    }

    protected function getImageSelectors(): array
    {
        return [
            '.fop-images img',
            '.product-image img',
            '.gallery img',
            '[data-product-image]',
            '.product-gallery img',
            '.carousel img',
            '.product-media img',
        ];
    }

    protected function getBrandSelectors(): array
    {
        return [
            '[data-testid="product-brand"]',
            '.product-brand',
            '.brand-name',
            '[data-brand]',
            'a[href*="/brands/"]',
            '.fop-brand',
        ];
    }

    protected function getWeightSelectors(): array
    {
        return [
            '.product-weight',
            '[data-weight]',
            '.size-selector option:checked',
            '.weight-selector .selected',
            '.product-size',
            '.fop-catchweight',
        ];
    }

    protected function getIngredientsSelectors(): array
    {
        return [
            '.ingredients',
            '[data-ingredients]',
            '.product-ingredients',
            '#ingredients',
            '.composition',
            '[data-testid="ingredients"]',
            '.ingredient-list',
            '.fop-ingredients',
        ];
    }

    protected function getOutOfStockSelectors(): array
    {
        return [
            '.out-of-stock',
            '[data-stock-status="out"]',
            '.sold-out',
            '.unavailable',
            '[data-testid="out-of-stock"]',
            '.fop-out-of-stock',
        ];
    }

    protected function getInStockSelectors(): array
    {
        return [
            '.in-stock',
            '[data-stock-status="in"]',
            '.available',
            '[data-testid="in-stock"]',
        ];
    }

    protected function getAddToCartSelectors(): array
    {
        return [];
    }

    protected function getRetailerSpecificBrandSkipWords(): array
    {
        return [
            'home',
            'products',
            'pets',
            'accessories',
            'shop',
            'all',
            'sale',
            'and',
            'or',
        ];
    }

    public function extractExternalId(string $url, ?Crawler $crawler = null, array $jsonLdData = []): ?string
    {
        if (preg_match('/\/products\/[a-z0-9-]+-(\d+)$/i', $url, $matches)) {
            return $matches[1];
        }

        if (! empty($jsonLdData['sku'])) {
            return (string) $jsonLdData['sku'];
        }

        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];
            if (! is_array($offers)) {
                $offers = [$offers];
            }
            if (! empty($offers[0]['sku'])) {
                return (string) $offers[0]['sku'];
            }
        }

        if ($crawler !== null) {
            $idSelectors = [
                '[data-product-id]',
                '[data-sku]',
                '[data-product-code]',
                'input[name="product_id"]',
                '[data-fop-id]',
            ];

            foreach ($idSelectors as $selector) {
                try {
                    $element = $crawler->filter($selector);
                    if ($element->count() > 0) {
                        $id = $element->first()->attr('data-product-id')
                            ?? $element->first()->attr('data-sku')
                            ?? $element->first()->attr('data-product-code')
                            ?? $element->first()->attr('data-fop-id')
                            ?? $element->first()->attr('value');

                        if ($id !== null && trim($id) !== '') {
                            return trim($id);
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return null;
    }

    protected function extractCategory(Crawler $crawler, string $url): ?string
    {
        return $this->categoryExtractor->extractFromBreadcrumbs($crawler)
            ?? $this->categoryExtractor->extractFromUrl($url);
    }

    protected function buildMetadata(
        Crawler $crawler,
        string $url,
        ?string $externalId,
        array $jsonLdData,
        array $weightData
    ): array {
        return array_merge(parent::buildMetadata($crawler, $url, $externalId, $jsonLdData, $weightData), [
            'rating_value' => $jsonLdData['aggregateRating']['ratingValue'] ?? null,
            'review_count' => $jsonLdData['aggregateRating']['reviewCount'] ?? null,
        ]);
    }

    protected function extractBarcode(Crawler $crawler, array $jsonLdData): ?string
    {
        $fields = ['gtin', 'gtin13', 'ean'];

        foreach ($fields as $field) {
            if (! empty($jsonLdData[$field])) {
                return (string) $jsonLdData[$field];
            }
        }

        $element = $this->selectFirst(
            $crawler,
            ['[data-barcode]', '[data-ean]', '[data-gtin]', '.barcode', '.ean'],
            'Barcode',
            fn (Crawler $node) => $this->extractBarcodeFromElement($node) !== null
        );

        return $element !== null ? $this->extractBarcodeFromElement($element) : null;
    }

    private function extractBarcodeFromElement(Crawler $element): ?string
    {
        $barcode = $element->attr('data-barcode')
            ?? $element->attr('data-ean')
            ?? $element->attr('data-gtin');

        if ($barcode !== null && trim($barcode) !== '') {
            return trim($barcode);
        }

        $text = trim($element->text());

        return $text !== '' ? $text : null;
    }

    private function isBlockedPage(Crawler $crawler, string $html): bool
    {
        if (str_contains(strtolower($html), 'captcha') || str_contains(strtolower($html), 'robot check')) {
            return true;
        }

        $title = $this->selectFirst(
            $crawler,
            ['title'],
            'Blocked page title',
            fn (Crawler $node) => trim($node->text()) !== ''
        );

        if ($title !== null) {
            $titleText = strtolower($title->text());
            if (str_contains($titleText, 'access denied') || str_contains($titleText, 'blocked')) {
                return true;
            }
        }

        return false;
    }
}
