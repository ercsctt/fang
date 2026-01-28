<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Tesco;

use App\Crawler\Extractors\BaseProductListingUrlExtractor;

class TescoProductListingUrlExtractor extends BaseProductListingUrlExtractor
{
    public function canHandle(string $url): bool
    {
        return str_contains($url, 'tesco.com');
    }

    protected function getProductLinkSelectors(): array
    {
        return [
            'a[href*="/products/"]',
        ];
    }

    protected function isProductUrl(string $url): bool
    {
        // Tesco product URLs: /groceries/en-GB/products/PRODUCTCODE
        // Product codes are numeric (TPNs - Tesco Product Numbers)
        return (bool) preg_match('/\/groceries\/en-GB\/products\/\d+/', $url);
    }

    protected function getRetailerSlug(): string
    {
        return 'tesco';
    }

    /**
     * Extract category from the source URL.
     * Tesco-specific extraction since CategoryExtractor may not handle Tesco URLs.
     */
    protected function extractCategory(string $url): ?string
    {
        // First try the injected CategoryExtractor
        $category = parent::extractCategory($url);
        if ($category !== null) {
            return $category;
        }

        // Extract category from the source URL path
        // e.g., /groceries/en-GB/shop/pets/dog-food-and-treats/ -> "dog-food-and-treats"
        if (preg_match('/\/shop\/pets?\/([\w-]+)/', $url, $matches)) {
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
