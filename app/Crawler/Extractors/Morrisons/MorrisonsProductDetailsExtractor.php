<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Morrisons;

use App\Crawler\Extractors\BaseProductDetailsExtractor;
use App\Crawler\Services\CategoryExtractor;
use Symfony\Component\DomCrawler\Crawler;

class MorrisonsProductDetailsExtractor extends BaseProductDetailsExtractor
{
    public function __construct(
        private readonly CategoryExtractor $categoryExtractor,
    ) {}

    public function canHandle(string $url): bool
    {
        if (! str_contains($url, 'morrisons.com')) {
            return false;
        }

        return (bool) preg_match('/\/products\/[\w-]+\/\w+/', $url);
    }

    protected function getRetailerSlug(): string
    {
        return 'morrisons';
    }

    protected function getTitleSelectors(): array
    {
        return [
            'h1[data-test="product-title"]',
            '.pdp-main-details__title',
            '.product-title',
            'h1.product-name',
            '[data-testid="product-title"]',
            '.product-details h1',
            'h1',
        ];
    }

    protected function getPriceSelectors(): array
    {
        return [
            '[data-test="product-price"]',
            '.pdp-main-details__price-value',
            '.price--current',
            '.product-price__value',
            '[data-testid="product-price"]',
            '.price-value',
            '.price',
        ];
    }

    protected function getOriginalPriceSelectors(): array
    {
        return [
            '[data-test="was-price"]',
            '.price--was',
            '.was-price',
            '.price-was',
            '.original-price',
            '.strikethrough-price',
            's.price',
            'del.price',
        ];
    }

    protected function getDescriptionSelectors(): array
    {
        return [
            '[data-test="product-description"]',
            '.pdp-description__content',
            '.product-description',
            '.product__description',
            '#product-description',
            '.product-info-block__content',
        ];
    }

    protected function getImageSelectors(): array
    {
        return [
            '.pdp-main-details__image img',
            '.product-image img',
            '[data-test="product-image"] img',
            '.product__images img',
            '.pdp-image img',
            '.product-gallery img',
        ];
    }

    protected function getBrandSelectors(): array
    {
        return [
            '[data-test="product-brand"]',
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
            '.product-weight',
            '.product__weight',
            '[data-weight]',
            '.product-size',
            '.pdp-main-details__weight',
        ];
    }

    protected function getIngredientsSelectors(): array
    {
        return [
            '[data-test="ingredients"]',
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
            '[data-test="out-of-stock"]',
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
            '[data-test="in-stock"]',
            '.available',
            '[data-testid="in-stock"]',
        ];
    }

    protected function getAddToCartSelectors(): array
    {
        return [
            '.add-to-basket:not([disabled])',
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
            'morrisons',
        ];
    }

    public function extractExternalId(string $url, ?Crawler $crawler = null, array $jsonLdData = []): ?string
    {
        if (preg_match('/\/products\/[\w-]+\/(\w+)/', $url, $matches)) {
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
                '[data-product-sku]',
                'input[name="product_id"]',
            ];

            foreach ($idSelectors as $selector) {
                try {
                    $element = $crawler->filter($selector);
                    if ($element->count() > 0) {
                        $id = $element->first()->attr('data-product-id')
                            ?? $element->first()->attr('data-sku')
                            ?? $element->first()->attr('data-product-sku')
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
            'price_dropped_pence' => $this->extractPriceDroppedPrice($crawler),
            'my_morrisons_price_pence' => $this->extractMyMorrisonsPrice($crawler),
            'rating_value' => $jsonLdData['aggregateRating']['ratingValue'] ?? null,
            'review_count' => $jsonLdData['aggregateRating']['reviewCount'] ?? null,
        ]);
    }

    private function extractPriceDroppedPrice(Crawler $crawler): ?int
    {
        $element = $this->selectFirst(
            $crawler,
            [
                '[data-test="price-dropped"]',
                '.price-dropped__value',
                '.offer-price--dropped',
                '.price--dropped',
                '[data-testid="price-dropped"]',
            ],
            'Price dropped',
            fn (Crawler $node) => ($this->parsePriceToPence($node->text()) ?? 0) > 0
        );

        return $element !== null ? $this->parsePriceToPence($element->text()) : null;
    }

    private function extractMyMorrisonsPrice(Crawler $crawler): ?int
    {
        $element = $this->selectFirst(
            $crawler,
            [
                '[data-test="my-morrisons-price"]',
                '.my-morrisons-price__value',
                '.member-price',
                '.loyalty-price',
                '[data-testid="my-morrisons-price"]',
            ],
            'My Morrisons price',
            fn (Crawler $node) => ($this->parsePriceToPence($node->text()) ?? 0) > 0
        );

        return $element !== null ? $this->parsePriceToPence($element->text()) : null;
    }
}
