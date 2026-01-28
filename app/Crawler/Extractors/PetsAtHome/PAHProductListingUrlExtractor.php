<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\PetsAtHome;

use App\Crawler\Extractors\BaseProductListingUrlExtractor;

class PAHProductListingUrlExtractor extends BaseProductListingUrlExtractor
{
    public function canHandle(string $url): bool
    {
        return str_contains($url, 'petsathome.com');
    }

    protected function getProductLinkSelectors(): array
    {
        return [
            'a[href*="/product/"]',
        ];
    }

    protected function isProductUrl(string $url): bool
    {
        // Pets at Home product URLs: /product/product-name-slug/PRODUCTCODE
        // Product codes can be like P71341 or 7136893P
        return (bool) preg_match('/\/product\/[a-z0-9-]+\/[A-Z0-9]+$/i', $url);
    }

    protected function getRetailerSlug(): string
    {
        return 'pets-at-home';
    }

    protected function supportsPagination(): bool
    {
        return true;
    }

    /**
     * Extract page number from a pagination URL.
     */
    protected function extractPageNumber(string $nextPageUrl, string $currentUrl): int
    {
        if (preg_match('/[?&]page=(\d+)/i', $nextPageUrl, $matches)) {
            return (int) $matches[1];
        }

        return 2; // Default to page 2 if we can't parse
    }

    /**
     * Extract category from the source URL path.
     * PAH uses its own category extraction patterns specific to their URL structure.
     */
    protected function extractCategory(string $url): ?string
    {
        // Extract category from the source URL path - PAH-specific patterns first
        // e.g., /shop/en/pets/dog/dog-food -> "dog-food"
        // e.g., /shop/en/pets/dog/dog-treats -> "dog-treats"
        // First try to match full category like "dog-food" or "cat-treats"
        if (preg_match('/\/(dog|cat|puppy|kitten)-(food|treats|accessories)/i', $url, $matches)) {
            return strtolower($matches[1]).'-'.strtolower($matches[2]);
        }

        // Fall back to just the animal type
        if (preg_match('/\/(dog|cat|puppy|kitten)(?:\/|$|\?)/i', $url, $matches)) {
            return strtolower($matches[1]);
        }

        // Fall back to CategoryExtractor
        return parent::extractCategory($url);
    }
}
