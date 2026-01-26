<?php

declare(strict_types=1);

namespace App\Crawler\Extractors;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductListingUrl;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class BMProductListingUrlExtractor implements ExtractorInterface
{
    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Extract all links from the page
        $allLinks = $crawler->filter('a[href]')
            ->each(fn (Crawler $node) => $node->attr('href'));

        $processedUrls = [];

        foreach ($allLinks as $link) {
            if (! $link) {
                continue;
            }

            // Normalize URL
            $normalizedUrl = $this->normalizeUrl($link, $url);

            // Skip duplicates
            if (in_array($normalizedUrl, $processedUrls)) {
                continue;
            }

            // Validate that this looks like a product URL
            if ($this->isProductUrl($normalizedUrl)) {
                $processedUrls[] = $normalizedUrl;

                Log::debug("Found product listing URL: {$normalizedUrl}");

                yield new ProductListingUrl(
                    url: $normalizedUrl,
                    retailer: 'bm',
                    category: $this->extractCategory($normalizedUrl),
                    metadata: [
                        'discovered_from' => $url,
                        'discovered_at' => now()->toIso8601String(),
                    ]
                );
            }
        }

        Log::info('Extracted '.count($processedUrls)." product listing URLs from {$url}");
    }

    public function canHandle(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host === 'bmstores.co.uk' || $host === 'www.bmstores.co.uk';
    }

    /**
     * Normalize a URL to absolute format.
     */
    private function normalizeUrl(string $url, string $baseUrl): string
    {
        // Already absolute URL
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? '';

        // Protocol-relative URL
        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        // Absolute path
        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$url}";
        }

        // Relative path
        $path = $parsedBase['path'] ?? '';
        $basePath = substr($path, 0, strrpos($path, '/') + 1);

        return "{$scheme}://{$host}{$basePath}{$url}";
    }

    /**
     * Check if a URL looks like a product URL.
     */
    private function isProductUrl(string $url): bool
    {
        // B&M product URLs typically contain /product/ or have numeric product IDs
        return preg_match('#/product/#', $url)
            || preg_match('#/p/\d+#', $url)
            || preg_match('#/pd/[a-z0-9-]+#i', $url);
    }

    /**
     * Extract category from URL if present.
     */
    private function extractCategory(string $url): ?string
    {
        // Try to extract category from URL path
        if (preg_match('/\/(pet|dog|cat|puppy)[^\/]*\//i', $url, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }
}
