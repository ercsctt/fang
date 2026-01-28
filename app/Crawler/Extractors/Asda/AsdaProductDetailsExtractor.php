<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Asda;

use App\Crawler\Extractors\BaseProductDetailsExtractor;
use App\Crawler\Services\CategoryExtractor;
use Symfony\Component\DomCrawler\Crawler;

class AsdaProductDetailsExtractor extends BaseProductDetailsExtractor
{
    public function __construct(
        private readonly CategoryExtractor $categoryExtractor,
    ) {}

    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'groceries.asda.com')) {
            return preg_match('/\/product\/(?:[a-z0-9-]+\/)?(\d+)(?:\/|$|\?)/i', $url) === 1;
        }

        return false;
    }

    protected function getRetailerSlug(): string
    {
        return 'asda';
    }

    protected function getTitleSelectors(): array
    {
        return [
            'h1[data-auto-id="pdp-product-title"]',
            '.pdp-main-details__title',
            '[data-testid="product-title"]',
            '.co-product__title',
            'h1.product-title',
            'h1',
        ];
    }

    protected function getPriceSelectors(): array
    {
        return [
            '[data-auto-id="pdp-price"] strong',
            '.pdp-main-details__price strong',
            '.co-product__price strong',
            '[data-testid="product-price"]',
            '.product-price .price',
            '.rollback-price',
            '.sale-price',
            '.offer-price',
            '.price strong',
            '.price',
        ];
    }

    protected function getOriginalPriceSelectors(): array
    {
        return [
            '.price-was',
            '.was-price',
            '.old-price',
            '.product-was-price',
            '.previous-price',
        ];
    }

    protected function getDescriptionSelectors(): array
    {
        return [
            '[data-auto-id="pdp-description"]',
            '.pdp-main-details__description',
            '.product-description',
            '.co-product__description',
            '[data-testid="product-description"]',
            '#description',
            '.product-info',
        ];
    }

    protected function getImageSelectors(): array
    {
        return [
            '[data-auto-id="pdp-image"] img',
            '.pdp-image-carousel img',
            '.product-image img',
            '.co-product__image img',
            '.gallery-image img',
            '[data-testid="product-image"] img',
        ];
    }

    protected function getBrandSelectors(): array
    {
        return [
            '[data-auto-id="pdp-brand"]',
            '.pdp-main-details__brand',
            '.product-brand',
            '.co-product__brand',
            '[data-testid="product-brand"]',
            '.brand-name',
        ];
    }

    protected function getWeightSelectors(): array
    {
        return [
            '[data-auto-id="pdp-weight"]',
            '.pdp-main-details__weight',
            '.product-weight',
            '.product-size',
            '[data-testid="product-weight"]',
        ];
    }

    protected function getIngredientsSelectors(): array
    {
        return [
            '[data-auto-id="pdp-ingredients"]',
            '.pdp-ingredients',
            '.product-ingredients',
            '#ingredients',
            '.ingredients',
            '.composition',
            '[data-testid="ingredients"]',
            '.ingredient-list',
        ];
    }

    protected function getOutOfStockSelectors(): array
    {
        return [
            '.out-of-stock',
            '[data-auto-id="out-of-stock"]',
            '.unavailable',
            '.sold-out',
            '[data-testid="out-of-stock"]',
        ];
    }

    protected function getInStockSelectors(): array
    {
        return [
            '.in-stock',
            '[data-auto-id="in-stock"]',
            '.available',
        ];
    }

    protected function getAddToCartSelectors(): array
    {
        return [
            '[data-auto-id="add-to-trolley"]',
            '.add-to-trolley',
            '[data-testid="add-to-basket"]',
        ];
    }

    protected function getQuantityPatterns(): array
    {
        return [
            '/(\d+)\s*(?:pack|count|pcs|pieces|tins|pouches|sachets|bags|cans)\b/i',
        ];
    }

    protected function getRetailerSpecificBrandSkipWords(): array
    {
        return [
            'asda',
            'extra',
            'smart',
        ];
    }

    protected function normalizeImageUrl(string $url): string
    {
        return $this->upgradeImageUrl($url);
    }

    protected function extractExternalId(string $url, Crawler $crawler, array $jsonLdData): ?string
    {
        return $this->extractProductId($url, $crawler);
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
            'product_id' => $externalId,
            'rating_value' => $this->extractRating($crawler),
            'review_count' => $this->extractReviewCount($crawler),
            'price_per_unit' => $this->extractPricePerUnit($crawler),
            'asda_rewards_price' => $this->extractAsdaRewardsPrice($crawler),
            'rollback_price' => $this->extractRollbackPrice($crawler),
        ]);
    }

    /**
     * Extract Asda rewards price in pence.
     */
    private function extractAsdaRewardsPrice(Crawler $crawler): ?int
    {
        $selectors = [
            '[data-auto-id="asda-rewards-price"]',
            '.asda-rewards-price',
            '[data-testid="asda-rewards-price"]',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $priceText = trim($element->first()->text());
                    $price = $this->parsePriceToPence($priceText);
                    if ($price !== null) {
                        return $price;
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }

    /**
     * Extract rollback price in pence.
     */
    private function extractRollbackPrice(Crawler $crawler): ?int
    {
        $selectors = [
            '.rollback-price',
            '.price-rollback',
            '[data-testid="rollback-price"]',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $priceText = trim($element->first()->text());
                    $price = $this->parsePriceToPence($priceText);
                    if ($price !== null) {
                        return $price;
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }

    /**
     * Extract price per unit (e.g., per kg).
     */
    private function extractPricePerUnit(Crawler $crawler): ?string
    {
        $selectors = [
            '.price-per-unit',
            '.unit-price',
            '[data-testid="unit-price"]',
            '.co-product__price-per-unit',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = trim($element->first()->text());
                    if (! empty($text)) {
                        return $text;
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }

    /**
     * Upgrade Asda image URL to larger size if possible.
     */
    private function upgradeImageUrl(string $url): string
    {
        if (str_contains($url, 'asda-groceries.co.uk') || str_contains($url, 'asda.com')) {
            $url = preg_replace('/[?&]w=\d+/', '', $url);
            $url = preg_replace('/[?&]h=\d+/', '', $url);

            if (! str_contains($url, '?')) {
                $url .= '?w=600&h=600';
            }
        }

        return $url;
    }

    /**
     * Extract product ID from URL or page.
     */
    public function extractProductId(string $url, ?Crawler $crawler = null): ?string
    {
        if (preg_match('/\/product\/(?:[a-z0-9-]+\/)?(\d+)(?:\/|$|\?)/i', $url, $matches)) {
            return $matches[1];
        }

        if ($crawler !== null) {
            try {
                $productElement = $crawler->filter('[data-product-id], [data-sku-id], [data-item-id]');
                if ($productElement->count() > 0) {
                    $id = $productElement->first()->attr('data-product-id')
                        ?? $productElement->first()->attr('data-sku-id')
                        ?? $productElement->first()->attr('data-item-id');

                    if ($id !== null && ! empty(trim($id))) {
                        return trim($id);
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }

    /**
     * Extract rating value.
     */
    private function extractRating(Crawler $crawler): ?float
    {
        $selectors = [
            '.rating-value',
            '[data-testid="rating-value"]',
            '.product-rating',
            '.star-rating',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = trim($element->first()->text());
                    if (preg_match('/(\d+(?:\.\d+)?)/', $text, $matches)) {
                        return (float) $matches[1];
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }

    /**
     * Extract review count.
     */
    private function extractReviewCount(Crawler $crawler): ?int
    {
        $selectors = [
            '.review-count',
            '[data-testid="review-count"]',
            '.reviews-count',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $text = trim($element->first()->text());
                    if (preg_match('/(\d+)/', $text, $matches)) {
                        return (int) $matches[1];
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }
}
