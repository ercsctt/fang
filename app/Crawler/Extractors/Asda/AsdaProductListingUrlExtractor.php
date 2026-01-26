<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Asda;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductListingUrl;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class AsdaProductListingUrlExtractor implements ExtractorInterface
{
    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Asda product URLs contain /product/ followed by product identifier
        // Format: /product/[product-name]/[SKU-ID] or /product/[SKU-ID]
        $productLinks = $this->extractProductLinks($crawler);

        $processedIds = [];

        foreach ($productLinks as $link) {
            if (! $link) {
                continue;
            }

            $productId = $this->extractProductId($link);
            if ($productId === null) {
                continue;
            }

            // Skip duplicates
            if (in_array($productId, $processedIds)) {
                continue;
            }

            $normalizedUrl = $this->normalizeProductUrl($link);

            $processedIds[] = $productId;

            Log::debug("AsdaProductListingUrlExtractor: Found product ID: {$productId}");

            yield new ProductListingUrl(
                url: $normalizedUrl,
                retailer: 'asda',
                category: $this->extractCategory($url),
                metadata: [
                    'product_id' => $productId,
                    'discovered_from' => $url,
                    'discovered_at' => now()->toIso8601String(),
                ]
            );
        }

        Log::info('AsdaProductListingUrlExtractor: Extracted '.count($processedIds)." product URLs from {$url}");
    }

    public function canHandle(string $url): bool
    {
        // Handle Asda Groceries category/aisle pages but not individual product pages
        if (str_contains($url, 'groceries.asda.com')) {
            // Aisle/category pages
            if (str_contains($url, '/aisle/')) {
                return true;
            }
            // Shelf pages
            if (str_contains($url, '/shelf/')) {
                return true;
            }
            // Search pages
            if (str_contains($url, '/search/')) {
                return true;
            }
            // Super department pages (e.g., /super-department/pet-shop)
            if (str_contains($url, '/super-department/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract product links from the page.
     *
     * @return array<string>
     */
    private function extractProductLinks(Crawler $crawler): array
    {
        $links = [];

        // Common selectors for Asda product links
        $selectors = [
            'a[href*="/product/"]',
            '[data-auto-id="linkProductDetail"]',
            '.co-product__anchor',
            '.product-tile a',
            '.product-card a[href*="/product/"]',
            '.listing-item a[href*="/product/"]',
        ];

        foreach ($selectors as $selector) {
            try {
                $elements = $crawler->filter($selector);
                if ($elements->count() > 0) {
                    $elements->each(function (Crawler $node) use (&$links) {
                        $href = $node->attr('href');
                        if ($href && str_contains($href, '/product/') && ! in_array($href, $links)) {
                            $links[] = $href;
                        }
                    });
                }
            } catch (\Exception $e) {
                Log::debug("AsdaProductListingUrlExtractor: Selector {$selector} failed: {$e->getMessage()}");
            }
        }

        // Also try to extract from JSON data embedded in scripts (Asda uses dynamic loading)
        try {
            $scripts = $crawler->filter('script');
            foreach ($scripts as $script) {
                $content = $script->textContent;

                // Look for product data in inline scripts
                if (str_contains($content, '"productId"') || str_contains($content, '"skuId"')) {
                    // Extract product IDs from JSON-like content
                    if (preg_match_all('/"(?:productId|skuId)"\s*:\s*"(\d+)"/', $content, $matches)) {
                        foreach ($matches[1] as $productId) {
                            $productUrl = "https://groceries.asda.com/product/{$productId}";
                            if (! in_array($productUrl, $links)) {
                                $links[] = $productUrl;
                            }
                        }
                    }
                }

                // Look for product URLs in the page state/data
                if (preg_match_all('/\/product\/[a-z0-9-]+\/(\d+)/i', $content, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $productUrl = "https://groceries.asda.com{$match[0]}";
                        if (! in_array($productUrl, $links)) {
                            $links[] = $productUrl;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("AsdaProductListingUrlExtractor: Script extraction failed: {$e->getMessage()}");
        }

        return $links;
    }

    /**
     * Extract product ID from Asda URL.
     *
     * SKU IDs are typically numeric identifiers.
     */
    private function extractProductId(string $url): ?string
    {
        // Pattern: /product/product-name/123456789 or /product/123456789
        if (preg_match('/\/product\/(?:[a-z0-9-]+\/)?(\d+)(?:\/|$|\?)/i', $url, $matches)) {
            return $matches[1];
        }

        // Pattern: productId in query string
        if (preg_match('/[?&]productId=(\d+)/i', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Normalize product URL to canonical form.
     */
    private function normalizeProductUrl(string $url): string
    {
        // If it's already a full URL, use it
        if (str_starts_with($url, 'https://')) {
            // Remove query parameters and fragments
            $cleanUrl = preg_replace('/[?#].*$/', '', $url);

            return $cleanUrl ?? $url;
        }

        // If it's a relative URL, make it absolute
        if (str_starts_with($url, '/')) {
            return 'https://groceries.asda.com'.$url;
        }

        return $url;
    }

    /**
     * Extract category from the source URL.
     */
    private function extractCategory(string $url): ?string
    {
        // Extract from aisle path: /aisle/pet-shop/dog/dog-food/
        if (preg_match('/\/aisle\/([^\/]+(?:\/[^\/]+)*)(?:\/|$|\?)/i', $url, $matches)) {
            $path = $matches[1];
            $parts = explode('/', $path);

            // Return the most specific category (last meaningful part)
            $filteredParts = array_filter($parts, fn ($part) => ! empty($part) && $part !== 'all');
            if (! empty($filteredParts)) {
                return str_replace('-', ' ', end($filteredParts));
            }
        }

        // Extract from shelf path
        if (preg_match('/\/shelf\/([^\/]+)/i', $url, $matches)) {
            return str_replace('-', ' ', $matches[1]);
        }

        // Extract from super-department path
        if (preg_match('/\/super-department\/([^\/]+)/i', $url, $matches)) {
            return str_replace('-', ' ', $matches[1]);
        }

        // Extract from search query
        if (preg_match('/\/search\/([^\/\?]+)/i', $url, $matches)) {
            return urldecode($matches[1]);
        }

        return null;
    }
}
