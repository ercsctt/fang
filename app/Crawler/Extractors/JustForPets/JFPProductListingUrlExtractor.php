<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\JustForPets;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\PaginatedUrl;
use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\Concerns\ExtractsPagination;
use App\Crawler\Extractors\Concerns\NormalizesUrls;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class JFPProductListingUrlExtractor implements ExtractorInterface
{
    use ExtractsPagination;
    use NormalizesUrls;

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Just for Pets uses various product URL formats
        // Common patterns: /product/, /p/, /-p followed by product ID
        $productLinks = $crawler->filter('a[href]')
            ->each(fn (Crawler $node) => $node->attr('href'));

        $processedUrls = [];

        foreach ($productLinks as $link) {
            if (! $link) {
                continue;
            }

            $normalizedUrl = $this->normalizeUrl($link, $url);

            if (in_array($normalizedUrl, $processedUrls)) {
                continue;
            }

            if ($this->isProductUrl($normalizedUrl)) {
                $processedUrls[] = $normalizedUrl;

                Log::debug("JFPProductListingUrlExtractor: Found product URL: {$normalizedUrl}");

                yield new ProductListingUrl(
                    url: $normalizedUrl,
                    retailer: 'just-for-pets',
                    category: $this->extractCategory($url),
                    metadata: [
                        'discovered_from' => $url,
                        'discovered_at' => now()->toIso8601String(),
                    ]
                );
            }
        }

        Log::info('JFPProductListingUrlExtractor: Extracted '.count($processedUrls)." product listing URLs from {$url}");

        // Extract next page URL if available
        $nextPageUrl = $this->findNextPageLink($crawler, $url);
        if ($nextPageUrl !== null) {
            $currentPage = $this->extractCurrentPageNumber($url);
            yield new PaginatedUrl(
                url: $nextPageUrl,
                retailer: 'just-for-pets',
                page: $currentPage + 1,
                category: $this->extractCategory($url),
                discoveredFrom: $url,
            );
        }
    }

    public function canHandle(string $url): bool
    {
        return str_contains($url, 'justforpetsonline.co.uk');
    }

    private function isProductUrl(string $url): bool
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

    private function extractCategory(string $url): ?string
    {
        // Extract category from the source URL path
        if (preg_match('#/(dog|cat|puppy|kitten)[/-]?(food|treats|accessories)?#i', $url, $matches)) {
            $animal = strtolower($matches[1]);
            $type = isset($matches[2]) ? strtolower($matches[2]) : null;

            return $type ? "{$animal}-{$type}" : $animal;
        }

        return null;
    }

    /**
     * Normalize a pagination URL (required by ExtractsPagination trait).
     */
    protected function normalizePageUrl(string $href, string $baseUrl): string
    {
        return $this->normalizeUrl($href, $baseUrl);
    }
}
