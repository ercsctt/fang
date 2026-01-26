<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Amazon;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductListingUrl;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class AmazonProductListingUrlExtractor implements ExtractorInterface
{
    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Amazon product URLs contain /dp/ASIN where ASIN is 10 characters
        // Also handle /gp/product/ASIN and /gp/aw/d/ASIN (mobile) formats
        $productLinks = $crawler->filter('a[href*="/dp/"], a[href*="/gp/product/"], a[href*="/gp/aw/d/"]')
            ->each(fn (Crawler $node) => $node->attr('href'));

        $processedAsins = [];

        foreach ($productLinks as $link) {
            if (! $link) {
                continue;
            }

            $asin = $this->extractAsin($link);
            if ($asin === null) {
                continue;
            }

            // Skip duplicates
            if (in_array($asin, $processedAsins)) {
                continue;
            }

            // Skip sponsored products links that might have tracking params
            // but keep the core product URL
            $normalizedUrl = $this->normalizeProductUrl($asin);

            $processedAsins[] = $asin;

            Log::debug("AmazonProductListingUrlExtractor: Found product ASIN: {$asin}");

            yield new ProductListingUrl(
                url: $normalizedUrl,
                retailer: 'amazon-uk',
                category: $this->extractCategory($url),
                metadata: [
                    'asin' => $asin,
                    'discovered_from' => $url,
                    'discovered_at' => now()->toIso8601String(),
                ]
            );
        }

        Log::info('AmazonProductListingUrlExtractor: Extracted '.count($processedAsins)." product ASINs from {$url}");
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
     * Normalize product URL to canonical form.
     */
    private function normalizeProductUrl(string $asin): string
    {
        return "https://www.amazon.co.uk/dp/{$asin}";
    }

    /**
     * Extract category from the source URL.
     */
    private function extractCategory(string $url): ?string
    {
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
}
