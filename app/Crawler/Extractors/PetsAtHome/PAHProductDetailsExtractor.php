<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\PetsAtHome;

use App\Crawler\Extractors\BaseProductDetailsExtractor;
use App\Crawler\Services\CategoryExtractor;
use Symfony\Component\DomCrawler\Crawler;

class PAHProductDetailsExtractor extends BaseProductDetailsExtractor
{
    public function __construct(
        private readonly CategoryExtractor $categoryExtractor,
    ) {}

    public function canHandle(string $url): bool
    {
        if (str_contains($url, 'petsathome.com')) {
            return (bool) preg_match('/\/product\/[a-z0-9-]+\/[A-Z0-9]+$/i', $url);
        }

        return false;
    }

    protected function getRetailerSlug(): string
    {
        return 'pets-at-home';
    }

    protected function getBrandConfigKey(): string
    {
        return 'petsathome';
    }

    protected function getTitleSelectors(): array
    {
        return [
            'h1[data-testid="product-title"]',
            'h1.product-title',
            '.product-name h1',
            '.pdp-title',
            '[data-product-title]',
            'h1',
        ];
    }

    protected function getPriceSelectors(): array
    {
        return [
            '[data-testid="product-price"]',
            '.product-price',
            '.price-current',
            '[data-price]',
            '.pdp-price',
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
        ];
    }

    protected function getDescriptionSelectors(): array
    {
        return [
            '[data-testid="product-description"]',
            '.product-description',
            '.description-content',
            '.pdp-description',
            '#product-description',
            '.product-info-description',
        ];
    }

    protected function getImageSelectors(): array
    {
        return [
            '.product-image img',
            '.gallery img',
            '[data-product-image]',
            '.pdp-image img',
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

    protected function getQuantityPatterns(): array
    {
        return [
            '/(\d+)\s*(?:pack|x|pcs|pieces|count)\b/i',
        ];
    }

    protected function extractWeightAndQuantity(string $title, Crawler $crawler, array $jsonLdData = []): array
    {
        $weight = null;
        $quantity = null;

        if (! empty($jsonLdData['offers'])) {
            $offers = $jsonLdData['offers'];
            if (! is_array($offers)) {
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
        if (preg_match('/\/product\/[^\/]+\/([A-Z0-9]+)$/i', $url, $matches)) {
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

        $crawler = $crawler ?? new Crawler('');

        $idSelectors = [
            '[data-product-id]',
            '[data-sku]',
            '[data-product-code]',
            'input[name="product_id"]',
        ];

        foreach ($idSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $id = $element->first()->attr('data-product-id')
                        ?? $element->first()->attr('data-sku')
                        ?? $element->first()->attr('data-product-code')
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
}
