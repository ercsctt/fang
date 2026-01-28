<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Zooplus;

use App\Crawler\Extractors\BaseProductListingUrlExtractor;

class ZooplusProductListingUrlExtractor extends BaseProductListingUrlExtractor
{
    public function canHandle(string $url): bool
    {
        // Only handle category/listing pages, not product pages
        return str_contains($url, 'zooplus.co.uk')
            && ! $this->isProductUrl($url);
    }

    protected function getProductLinkSelectors(): array
    {
        return [
            'a[href*="/shop/dogs/"]',
        ];
    }

    protected function isProductUrl(string $url): bool
    {
        // Zooplus product URLs end with a numeric ID after an underscore
        // Example: /shop/dogs/dry_dog_food/brand/product-name_123456
        // Category URLs don't have numeric IDs at the end
        return (bool) preg_match('/\/shop\/dogs\/[a-z0-9_\/]+\/[a-z0-9-]+_(\d{4,})/i', $url);
    }

    protected function getRetailerSlug(): string
    {
        return 'zooplus-uk';
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
        // e.g., /shop/dogs/dry_dog_food -> "dry_dog_food"
        if (preg_match('/\/shop\/dogs\/([\w_]+)/', $url, $matches)) {
            return $matches[1];
        }

        // Broader category extraction
        if (preg_match('/\/(dog|puppy)[_\/]?(food|treats|chews)?/i', $url, $matches)) {
            $animal = strtolower($matches[1]);
            $type = isset($matches[2]) ? strtolower($matches[2]) : null;

            return $type ? "{$animal}_{$type}" : $animal;
        }

        return null;
    }
}
