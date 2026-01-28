<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Waitrose;

use App\Crawler\Extractors\BaseProductListingUrlExtractor;

class WaitroseProductListingUrlExtractor extends BaseProductListingUrlExtractor
{
    public function canHandle(string $url): bool
    {
        return str_contains($url, 'waitrose.com');
    }

    protected function getProductLinkSelectors(): array
    {
        return [
            'a[href*="/products/"]',
        ];
    }

    protected function isProductUrl(string $url): bool
    {
        // Waitrose product URLs: /ecom/products/{product-slug}/{product-id}
        // Product IDs are alphanumeric
        return (bool) preg_match('/\/ecom\/products\/[a-z0-9-]+\/[a-z0-9-]+/i', $url);
    }

    protected function getRetailerSlug(): string
    {
        return 'waitrose';
    }

    protected function supportsPagination(): bool
    {
        return true;
    }

    /**
     * Extract category from the source URL path.
     * Waitrose uses its own category extraction patterns specific to their URL structure.
     */
    protected function extractCategory(string $url): ?string
    {
        // Extract category from the source URL path - Waitrose-specific patterns first
        // e.g., /ecom/shop/browse/groceries/pet/dog/dog_food -> "dog_food"
        if (preg_match('/\/pet\/dog\/([\w_]+)/', $url, $matches)) {
            return $matches[1];
        }

        // Broader pet category extraction
        if (preg_match('/\/(dog|cat|puppy|kitten)[_\/]?(food|treats)?/i', $url, $matches)) {
            $animal = strtolower($matches[1]);
            $type = isset($matches[2]) && $matches[2] !== '' ? strtolower($matches[2]) : null;

            return $type ? "{$animal}_{$type}" : $animal;
        }

        // Fall back to CategoryExtractor
        return parent::extractCategory($url);
    }
}
