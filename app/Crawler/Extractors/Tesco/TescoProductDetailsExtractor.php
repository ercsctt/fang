<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Tesco;

use App\Crawler\Extractors\BaseProductDetailsExtractor;
use App\Crawler\Services\CategoryExtractor;
use Symfony\Component\DomCrawler\Crawler;

class TescoProductDetailsExtractor extends BaseProductDetailsExtractor
{
    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'tesco.com')) {
            return (bool) preg_match('/\/groceries\/en-GB\/products\/\d+/', $url);
        }

        return false;
    }

    protected function getRetailerSlug(): string
    {
        return 'tesco';
    }

    protected function getTitleSelectors(): array
    {
        return [
            'h1[data-auto="product-title"]',
            '.product-details-tile__title',
            '.product__title',
            'h1.product-title',
            '.pdp-title',
            '[data-testid="product-title"]',
            'h1',
        ];
    }

    protected function getPriceSelectors(): array
    {
        return [
            '[data-auto="price-value"]',
            '.price-per-sellable-unit .value',
            '.product__price',
            '.product-details-tile__price-value',
            '[data-testid="product-price"]',
            '.beans-price__text',
            '.price',
        ];
    }

    protected function getOriginalPriceSelectors(): array
    {
        return [
            '.price-per-sellable-unit .was-price',
            '.product__price--was',
            '.was-price',
            '.price-was',
            '.original-price',
            '[data-auto="was-price"]',
            's.price',
            'del.price',
            '.strikethrough-price',
        ];
    }

    protected function getDescriptionSelectors(): array
    {
        return [
            '[data-auto="product-description"]',
            '.product-info-block__content',
            '.product-description',
            '.product__description',
            '#product-description',
            '.product-details-tile__description',
        ];
    }

    protected function getImageSelectors(): array
    {
        return [
            '.product-image-wrapper img',
            '.product-image img',
            '[data-auto="product-image"] img',
            '.product__images img',
            '.pdp-image img',
            '.product-gallery img',
        ];
    }

    protected function getBrandSelectors(): array
    {
        return [
            '[data-auto="product-brand"]',
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
            '[data-auto="product-weight"]',
            '.product-details-tile__weight',
            '.product-weight',
            '.product__weight',
            '[data-weight]',
            '.product-size',
        ];
    }

    protected function getIngredientsSelectors(): array
    {
        return [
            '[data-auto="ingredients"]',
            '.product-info-block--ingredients .product-info-block__content',
            '.ingredients',
            '.product-ingredients',
            '#ingredients',
            '.composition',
            '[data-testid="ingredients"]',
        ];
    }

    protected function getOutOfStockSelectors(): array
    {
        return [
            '.out-of-stock',
            '[data-auto="out-of-stock"]',
            '.sold-out',
            '.unavailable',
            '.product--unavailable',
            '[data-testid="out-of-stock"]',
        ];
    }

    protected function getInStockSelectors(): array
    {
        return [
            '.in-stock',
            '[data-auto="in-stock"]',
            '.available',
        ];
    }

    protected function getAddToCartSelectors(): array
    {
        return [
            '.add-to-basket:not([disabled])',
        ];
    }

    protected function getQuantityPatterns(): array
    {
        return [
            '/(\d+)\s*(?:pack|x|pcs|pieces|count)\b/i',
        ];
    }

    protected function getRetailerSpecificBrandSkipWords(): array
    {
        return ['tesco'];
    }

    protected function extractWeightAndQuantity(string $title, Crawler $crawler, array $jsonLdData = []): array
    {
        $weight = null;
        $quantity = null;

        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];

            if (isset($offers['@type']) || isset($offers['price'])) {
                $offers = [$offers];
            }

            foreach ($offers as $offer) {
                if (! empty($offer['name'])) {
                    $weight = $this->parseWeight((string) $offer['name']);
                    if ($weight !== null) {
                        break;
                    }
                }
            }
        }

        if ($weight === null) {
            $weight = $this->extractWeightFromSelectors($crawler);
        }

        if ($weight === null && $title !== '') {
            $weight = $this->parseWeight($title);
        }

        $quantity = $this->extractQuantityFromTitle($title);

        return [
            'weight' => $weight,
            'quantity' => $quantity,
        ];
    }

    public function extractExternalId(string $url, ?Crawler $crawler = null, array $jsonLdData = []): ?string
    {
        if (preg_match('/\/groceries\/en-GB\/products\/(\d+)/', $url, $matches)) {
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

        $crawler = $crawler ?? new Crawler('');

        $idSelectors = [
            '[data-product-id]',
            '[data-sku]',
            '[data-tpnb]',
            '[data-tpnc]',
            'input[name="product_id"]',
        ];

        foreach ($idSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $id = $element->first()->attr('data-product-id')
                        ?? $element->first()->attr('data-sku')
                        ?? $element->first()->attr('data-tpnb')
                        ?? $element->first()->attr('data-tpnc')
                        ?? $element->first()->attr('value');

                    if ($id !== null && trim($id) !== '') {
                        return trim($id);
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    protected function extractCategory(Crawler $crawler, string $url): ?string
    {
        $categoryExtractor = app(CategoryExtractor::class);

        return $categoryExtractor->extractFromBreadcrumbs(
            $crawler,
            [
                '.breadcrumb a',
                '.breadcrumbs a',
                '[data-auto="breadcrumb"] a',
                'nav.breadcrumb a',
                '.beans-breadcrumb a',
            ],
            1
        ) ?? $categoryExtractor->extractFromUrl($url);
    }

    protected function buildMetadata(
        Crawler $crawler,
        string $url,
        ?string $externalId,
        array $jsonLdData,
        array $weightData
    ): array {
        return array_merge(parent::buildMetadata($crawler, $url, $externalId, $jsonLdData, $weightData), [
            'clubcard_price_pence' => $this->extractClubcardPrice($crawler),
            'rating_value' => $jsonLdData['aggregateRating']['ratingValue'] ?? null,
            'review_count' => $jsonLdData['aggregateRating']['reviewCount'] ?? null,
        ]);
    }

    private function extractClubcardPrice(Crawler $crawler): ?int
    {
        $selectors = [
            '[data-auto="clubcard-price-value"]',
            '.clubcard-price__value',
            '.offer-price--clubcard',
            '[data-testid="clubcard-price"]',
            '.beans-price--clubcard .beans-price__text',
        ];

        $element = $this->selectFirst(
            $crawler,
            $selectors,
            'Clubcard price',
            fn (Crawler $node) => $this->extractPriceFromElement($node) !== null
        );

        return $element !== null ? $this->extractPriceFromElement($element) : null;
    }
}
