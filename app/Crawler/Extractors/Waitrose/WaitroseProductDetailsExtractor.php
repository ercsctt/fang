<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Waitrose;

use App\Crawler\Extractors\BaseProductDetailsExtractor;
use App\Crawler\Extractors\Concerns\ExtractsBarcode;
use App\Crawler\Services\CategoryExtractor;
use Symfony\Component\DomCrawler\Crawler;

class WaitroseProductDetailsExtractor extends BaseProductDetailsExtractor
{
    use ExtractsBarcode;

    public function __construct(
        private readonly CategoryExtractor $categoryExtractor,
    ) {}

    public function canHandle(string $url): bool
    {
        if (! str_contains($url, 'waitrose.com')) {
            return false;
        }

        return (bool) preg_match('/\/ecom\/products\/[a-z0-9-]+\/[a-z0-9-]+/i', $url);
    }

    protected function getRetailerSlug(): string
    {
        return 'waitrose';
    }

    protected function getTitleSelectors(): array
    {
        return [
            '[data-test="product-name"]',
            '[data-testid="product-name"]',
            'h1[data-test="product-title"]',
            '.product-hero__name',
            '.product__name',
            '.productName',
            '[data-productname]',
            'h1.product-title',
            '.pdp-title',
            'h1',
        ];
    }

    protected function getPriceSelectors(): array
    {
        return [
            '[data-test="product-price"]',
            '[data-testid="product-price"]',
            '.product-hero__price',
            '.price-per-sellable-unit .value',
            '.product__price',
            '.productPrice',
            '[data-price]',
            '.price',
        ];
    }

    protected function getOriginalPriceSelectors(): array
    {
        return [
            '[data-test="was-price"]',
            '[data-testid="was-price"]',
            '.price-per-sellable-unit .was-price',
            '.product__price--was',
            '.was-price',
            '.price-was',
            '.original-price',
            's.price',
            'del.price',
            '.strikethrough-price',
        ];
    }

    protected function getDescriptionSelectors(): array
    {
        return [
            '[data-test="product-description"]',
            '[data-testid="product-description"]',
            '.product-description__content',
            '.product-info-block__content',
            '.product-description',
            '.product__description',
            '#product-description',
            '.productDescription',
        ];
    }

    protected function getImageSelectors(): array
    {
        return [
            '[data-test="product-image"] img',
            '[data-testid="product-image"] img',
            '.product-hero__image img',
            '.product-image-wrapper img',
            '.product-image img',
            '.product__images img',
            '.pdp-image img',
            '.product-gallery img',
        ];
    }

    protected function getBrandSelectors(): array
    {
        return [
            '[data-test="product-brand"]',
            '[data-testid="product-brand"]',
            '.product-hero__brand',
            '.product__brand',
            '.product-brand',
            '.brand-name',
            '[data-brand]',
            'a[href*="/brands/"]',
        ];
    }

    protected function getWeightSelectors(): array
    {
        return [
            '[data-test="product-weight"]',
            '[data-testid="product-weight"]',
            '.product-hero__size',
            '.product-weight',
            '.product__weight',
            '[data-weight]',
            '.product-size',
        ];
    }

    protected function getIngredientsSelectors(): array
    {
        return [
            '[data-test="ingredients"]',
            '[data-testid="ingredients"]',
            '.product-info-block--ingredients .product-info-block__content',
            '.ingredients',
            '.product-ingredients',
            '#ingredients',
            '.composition',
        ];
    }

    protected function getOutOfStockSelectors(): array
    {
        return [
            '[data-test="out-of-stock"]',
            '[data-testid="out-of-stock"]',
            '.out-of-stock',
            '.sold-out',
            '.unavailable',
            '.product--unavailable',
        ];
    }

    protected function getInStockSelectors(): array
    {
        return [
            '[data-test="in-stock"]',
            '[data-testid="in-stock"]',
            '.in-stock',
            '.available',
        ];
    }

    protected function getAddToCartSelectors(): array
    {
        return [
            '.add-to-trolley:not([disabled])',
            'button[data-test="add-to-trolley"]:not([disabled])',
        ];
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
            'waitrose',
            'essential',
        ];
    }

    public function extractExternalId(string $url, ?Crawler $crawler = null, array $jsonLdData = []): ?string
    {
        if (preg_match('/\/ecom\/products\/[a-z0-9-]+\/([a-z0-9-]+)/i', $url, $matches)) {
            return $matches[1];
        }

        if (! empty($jsonLdData['sku'])) {
            return (string) $jsonLdData['sku'];
        }

        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];
            if (isset($offers['@type']) || isset($offers['price'])) {
                $offers = [$offers];
            }

            foreach ($offers as $offer) {
                if (! empty($offer['sku'])) {
                    return (string) $offer['sku'];
                }
            }
        }

        if ($crawler !== null) {
            $idSelectors = [
                '[data-product-id]',
                '[data-sku]',
                '[data-lineid]',
                '[data-productid]',
                'input[name="product_id"]',
            ];

            foreach ($idSelectors as $selector) {
                try {
                    $element = $crawler->filter($selector);
                    if ($element->count() > 0) {
                        $id = $element->first()->attr('data-product-id')
                            ?? $element->first()->attr('data-sku')
                            ?? $element->first()->attr('data-lineid')
                            ?? $element->first()->attr('data-productid')
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
            'mywaitrose_price_pence' => $this->extractMyWaitrosePrice($crawler),
            'rating_value' => $jsonLdData['aggregateRating']['ratingValue'] ?? null,
            'review_count' => $jsonLdData['aggregateRating']['reviewCount'] ?? null,
        ]);
    }

    private function extractMyWaitrosePrice(Crawler $crawler): ?int
    {
        $element = $this->selectFirst(
            $crawler,
            [
                '[data-test="mywaitrose-price"]',
                '[data-testid="mywaitrose-price"]',
                '.mywaitrose-price',
                '.member-price',
                '.loyalty-price',
                '.offer-price--mywaitrose',
            ],
            'MyWaitrose price',
            fn (Crawler $node) => ($this->parsePriceToPence($node->text()) ?? 0) > 0
        );

        return $element !== null ? $this->parsePriceToPence($element->text()) : null;
    }
}
