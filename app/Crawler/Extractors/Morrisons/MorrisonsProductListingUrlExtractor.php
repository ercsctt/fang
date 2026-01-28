<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Morrisons;

use App\Crawler\Extractors\BaseProductListingUrlExtractor;

class MorrisonsProductListingUrlExtractor extends BaseProductListingUrlExtractor
{
    public function canHandle(string $url): bool
    {
        return str_contains($url, 'morrisons.com') && ! $this->isProductUrl($url);
    }

    protected function getProductLinkSelectors(): array
    {
        return [
            'a[href*="/products/"]',
        ];
    }

    protected function isProductUrl(string $url): bool
    {
        // Morrisons product URLs: /products/[product-slug]/[SKU]
        // SKUs are numeric or alphanumeric identifiers
        return (bool) preg_match('/\/products\/[\w-]+\/\w+/', $url);
    }

    protected function getRetailerSlug(): string
    {
        return 'morrisons';
    }

    /**
     * Extract category from the source URL path.
     */
    protected function extractCategory(string $url): ?string
    {
        // First try the injected CategoryExtractor
        $category = parent::extractCategory($url);
        if ($category !== null) {
            return $category;
        }

        // Extract category from the source URL path
        // e.g., /browse/pet/dog -> "dog"
        if (preg_match('/\/browse\/pet\/([\w-]+)/', $url, $matches)) {
            return $matches[1];
        }

        // Broader pet category extraction
        if (preg_match('/\/(dog|cat|puppy|kitten)[-\/]?(food|treats)?/i', $url, $matches)) {
            $animal = strtolower($matches[1]);
            $type = isset($matches[2]) ? strtolower($matches[2]) : null;

            return $type ? "{$animal}-{$type}" : $animal;
        }

        return null;
    }
}
