<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Amazon;

use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\BaseProductListingUrlExtractor;
use App\Crawler\Services\CategoryExtractor;
use Generator;
use Symfony\Component\DomCrawler\Crawler;

class AmazonProductListingUrlExtractor extends BaseProductListingUrlExtractor
{
    public function __construct(
        ?CategoryExtractor $categoryExtractor = null,
    ) {
        parent::__construct($categoryExtractor);

        // Amazon-specific pagination selectors (override trait defaults)
        $this->nextPageSelectors = [
            'a.s-pagination-next',
            'a[aria-label="Go to next page"]',
            'a[aria-label*="Next"]',
            'a[aria-label*="next"]',
            '.a-pagination .a-last a',
            'a[rel="next"]',
        ];
    }

    public function canHandle(string $url): bool
    {
        // Handle Amazon UK search/category pages but not individual product pages
        if (str_contains($url, 'amazon.co.uk')) {
            // Category/search pages
            if (str_contains($url, '/s?') || str_contains($url, '/s/')) {
                return true;
            }
            // Browse node pages
            if (preg_match('/\/b\/?\?/', $url) || str_contains($url, '/b/')) {
                return true;
            }
        }

        return false;
    }

    protected function getProductLinkSelectors(): array
    {
        return [
            'a[href*="/dp/"]',
            'a[href*="/gp/product/"]',
            'a[href*="/gp/aw/d/"]',
        ];
    }

    protected function isProductUrl(string $url): bool
    {
        // Amazon product URLs contain /dp/ASIN, /gp/product/ASIN, or /gp/aw/d/ASIN
        return $this->extractAsin($url) !== null;
    }

    protected function getRetailerSlug(): string
    {
        return 'amazon-uk';
    }

    protected function supportsPagination(): bool
    {
        return true;
    }

    /**
     * Override to use ASIN-based deduplication instead of URL-based.
     * Amazon URLs can have many variations for the same product (tracking params, etc.).
     */
    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        if (! $this->shouldExtract($crawler, $html, $url)) {
            return;
        }

        $productLinks = $this->extractProductLinks($crawler);
        $processedAsins = [];

        foreach ($productLinks as $link) {
            if (! $link) {
                continue;
            }

            $asin = $this->extractAsin($link);
            if ($asin === null) {
                continue;
            }

            // Skip duplicates (ASIN-based deduplication)
            if (in_array($asin, $processedAsins)) {
                continue;
            }

            // Normalize to canonical product URL
            $normalizedUrl = $this->normalizeAsinToUrl($asin);

            $processedAsins[] = $asin;

            $this->logDebug("Found product ASIN: {$asin}");

            yield new ProductListingUrl(
                url: $normalizedUrl,
                retailer: $this->getRetailerSlug(),
                category: $this->extractCategoryFromSourceUrl($url),
                metadata: [
                    'asin' => $asin,
                    'discovered_from' => $url,
                    'discovered_at' => now()->toIso8601String(),
                ],
            );
        }

        $this->logInfo('Extracted '.count($processedAsins)." product ASINs from {$url}");

        // Extract pagination
        if ($this->supportsPagination()) {
            yield from $this->extractPagination($crawler, $url);
        }
    }

    /**
     * Extract ASIN from Amazon URL.
     *
     * ASINs are 10-character alphanumeric identifiers.
     */
    private function extractAsin(string $url): ?string
    {
        // Pattern: /dp/ASIN or /dp/ASIN/
        if (preg_match('/\/dp\/([A-Z0-9]{10})(?:\/|$|\?)/i', $url, $matches)) {
            return strtoupper($matches[1]);
        }

        // Pattern: /gp/product/ASIN
        if (preg_match('/\/gp\/product\/([A-Z0-9]{10})(?:\/|$|\?)/i', $url, $matches)) {
            return strtoupper($matches[1]);
        }

        // Pattern: /gp/aw/d/ASIN (mobile)
        if (preg_match('/\/gp\/aw\/d\/([A-Z0-9]{10})(?:\/|$|\?)/i', $url, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * Normalize ASIN to canonical product URL.
     */
    private function normalizeAsinToUrl(string $asin): string
    {
        return "https://www.amazon.co.uk/dp/{$asin}";
    }

    /**
     * Extract category from the source URL.
     * Amazon has custom category extraction from search queries and browse nodes.
     */
    private function extractCategoryFromSourceUrl(string $url): ?string
    {
        // First try the injected CategoryExtractor
        $category = parent::extractCategory($url);
        if ($category !== null) {
            return $category;
        }

        // Extract from search query
        if (preg_match('/[?&]k=([^&]+)/i', $url, $matches)) {
            $query = urldecode($matches[1]);

            // Try to identify category from common search terms
            if (preg_match('/\b(dog|puppy|kitten|cat)\s*(food|treats?|snacks?)/i', $query, $catMatches)) {
                return strtolower($catMatches[1]).'-'.strtolower($catMatches[2]);
            }

            return $query;
        }

        // Extract from breadcrumb-style URL
        // e.g., /Pet-Supplies/b/?ie=UTF8&node=340840031
        if (preg_match('/\/(Pet[^\/]*|Dog[^\/]*|Cat[^\/]*)\//i', $url, $matches)) {
            return str_replace(['-', '_'], ' ', $matches[1]);
        }

        // Try to extract rh (refinement hierarchy) parameter
        if (preg_match('/[?&]rh=[^&]*n%3A(\d+)/i', $url, $matches)) {
            // This is a browse node ID - we could map these to categories
            // For now, return null and let the product details extractor handle it
            return null;
        }

        return null;
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
}
