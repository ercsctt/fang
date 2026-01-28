<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\JustForPets;

use App\Crawler\Extractors\BaseProductListingUrlExtractor;

class JFPProductListingUrlExtractor extends BaseProductListingUrlExtractor
{
    public function canHandle(string $url): bool
    {
        return str_contains($url, 'justforpetsonline.co.uk');
    }

    protected function getProductLinkSelectors(): array
    {
        return [
            'a[href]',
        ];
    }

    protected function isProductUrl(string $url): bool
    {
        // Common e-commerce product URL patterns for specialist pet retailers
        // Patterns: /product/slug, /products/slug, /p/id, /-p-id, /slug-p-id.html
        return (bool) preg_match(
            '#/(product|products)/[a-z0-9-]+#i',
            $url
        )
            || preg_match('#/p/\d+#', $url)
            || preg_match('#-p-\d+\.html#i', $url)
            || preg_match('#/[a-z0-9-]+-\d+\.html$#i', $url)
            || preg_match('#/[a-z0-9-]+/[a-z0-9-]+\.html$#i', $url);
    }

    protected function getRetailerSlug(): string
    {
        return 'just-for-pets';
    }

    protected function supportsPagination(): bool
    {
        return true;
    }

    /**
     * Extract external ID from product URL.
     */
    public function extractExternalIdFromUrl(string $url): ?string
    {
        // Try various patterns to extract product ID
        // Pattern: /product/slug-123 or /products/slug-123
        if (preg_match('#/products?/[a-z0-9-]+-(\d+)(?:\.html)?$#i', $url, $matches)) {
            return $matches[1];
        }

        // Pattern: /p/123
        if (preg_match('#/p/(\d+)#', $url, $matches)) {
            return $matches[1];
        }

        // Pattern: -p-123.html
        if (preg_match('#-p-(\d+)\.html#i', $url, $matches)) {
            return $matches[1];
        }

        // Pattern: slug-123.html
        if (preg_match('#-(\d+)\.html$#i', $url, $matches)) {
            return $matches[1];
        }

        // Pattern: /category/product-slug.html - use slug as ID
        if (preg_match('#/([a-z0-9-]+)\.html$#i', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract category from the source URL path.
     * JFP uses its own category extraction patterns specific to their URL structure.
     */
    protected function extractCategory(string $url): ?string
    {
        // Extract category from the source URL path - JFP-specific patterns first
        if (preg_match('#/(dog|cat|puppy|kitten)[/-]?(food|treats|accessories)?#i', $url, $matches)) {
            $animal = strtolower($matches[1]);
            $type = isset($matches[2]) && $matches[2] !== '' ? strtolower($matches[2]) : null;

            return $type ? "{$animal}-{$type}" : $animal;
        }

        // Fall back to CategoryExtractor
        return parent::extractCategory($url);
    }
}
