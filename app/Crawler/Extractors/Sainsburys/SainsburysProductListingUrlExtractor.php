<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Sainsburys;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\PaginatedUrl;
use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\Concerns\ExtractsPagination;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class SainsburysProductListingUrlExtractor implements ExtractorInterface
{
    use ExtractsPagination;

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Sainsbury's uses /gol-ui/product/ for product URLs
        $productLinks = $crawler->filter('a[href*="/product/"]')
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

                Log::debug("SainsburysProductListingUrlExtractor: Found product URL: {$normalizedUrl}");

                yield new ProductListingUrl(
                    url: $normalizedUrl,
                    retailer: 'sainsburys',
                    category: $this->extractCategory($url),
                    metadata: [
                        'discovered_from' => $url,
                        'discovered_at' => now()->toIso8601String(),
                    ]
                );
            }
        }

        // Also try to extract product IDs from inline JSON data (Sainsbury's uses JavaScript rendering)
        $jsonProductUrls = $this->extractFromInlineJson($html, $url);
        foreach ($jsonProductUrls as $productUrl) {
            if (! in_array($productUrl, $processedUrls)) {
                $processedUrls[] = $productUrl;

                Log::debug("SainsburysProductListingUrlExtractor: Found product URL from JSON: {$productUrl}");

                yield new ProductListingUrl(
                    url: $productUrl,
                    retailer: 'sainsburys',
                    category: $this->extractCategory($url),
                    metadata: [
                        'discovered_from' => $url,
                        'discovered_at' => now()->toIso8601String(),
                        'source' => 'inline-json',
                    ]
                );
            }
        }

        Log::info('SainsburysProductListingUrlExtractor: Extracted '.count($processedUrls)." product listing URLs from {$url}");

        // Extract next page URL if available
        $nextPageUrl = $this->findNextPageLink($crawler, $url);
        if ($nextPageUrl !== null) {
            $currentPage = $this->extractCurrentPageNumber($url);
            yield new PaginatedUrl(
                url: $nextPageUrl,
                retailer: 'sainsburys',
                page: $currentPage + 1,
                category: $this->extractCategory($url),
                discoveredFrom: $url,
            );
        }
    }

    public function canHandle(string $url): bool
    {
        return str_contains($url, 'sainsburys.co.uk');
    }

    private function normalizeUrl(string $url, string $baseUrl): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? 'www.sainsburys.co.uk';

        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$url}";
        }

        $path = $parsedBase['path'] ?? '';
        $basePath = substr($path, 0, strrpos($path, '/') + 1);

        return "{$scheme}://{$host}{$basePath}{$url}";
    }

    private function isProductUrl(string $url): bool
    {
        // Sainsbury's product URLs have various patterns:
        // /gol-ui/product/[product-name]--[product-code]
        // /groceries-api/gol-services/product/v1/product?filter[product_sku]=[SKU]
        // /shop/[category]/[product-name]-[product-code]
        // Product codes are typically numeric or alphanumeric

        // Main product URL pattern
        if (preg_match('/\/gol-ui\/product\/[a-z0-9-]+--(\d+)/i', $url)) {
            return true;
        }

        // Alternative product URL pattern
        if (preg_match('/\/product\/[a-z0-9-]+-(\d+)/i', $url)) {
            return true;
        }

        // Shop product URL pattern
        if (preg_match('/\/shop\/gb\/groceries\/[^\/]+\/[a-z0-9-]+--(\d+)/i', $url)) {
            return true;
        }

        return false;
    }

    /**
     * Extract product URLs from inline JavaScript/JSON data.
     *
     * @return array<string>
     */
    private function extractFromInlineJson(string $html, string $baseUrl): array
    {
        $urls = [];

        // Look for product IDs in JSON data structures
        // Sainsbury's often embeds product data in script tags
        if (preg_match_all('/"product_uid"\s*:\s*"(\d+)"/', $html, $matches)) {
            foreach ($matches[1] as $productId) {
                // We don't have the full URL slug, but we can note the product ID
                // These would need to be resolved separately
                Log::debug("SainsburysProductListingUrlExtractor: Found product UID in JSON: {$productId}");
            }
        }

        // Look for full product URLs in JSON
        if (preg_match_all('/"url"\s*:\s*"([^"]*\/product\/[^"]+)"/', $html, $matches)) {
            foreach ($matches[1] as $productPath) {
                $productPath = stripslashes($productPath);
                if ($this->isProductUrl($productPath)) {
                    $urls[] = $this->normalizeUrl($productPath, $baseUrl);
                }
            }
        }

        // Look for product links in data attributes
        if (preg_match_all('/data-product-url="([^"]+)"/', $html, $matches)) {
            foreach ($matches[1] as $productPath) {
                if ($this->isProductUrl($productPath)) {
                    $urls[] = $this->normalizeUrl($productPath, $baseUrl);
                }
            }
        }

        return array_unique($urls);
    }

    private function extractCategory(string $url): ?string
    {
        // Extract category from the source URL path
        // e.g., /shop/gb/groceries/pets/dog-food-and-treats -> "dog-food-and-treats"
        if (preg_match('/\/pets?\/([\w-]+)/', $url, $matches)) {
            return $matches[1];
        }

        // Extract from gol-ui path
        if (preg_match('/\/gol-ui\/[^\/]+\/([\w-]+)/', $url, $matches)) {
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

    /**
     * Extract Sainsbury's product code from URL.
     * Product codes are typically numeric and appear after -- in the URL.
     */
    public function extractProductCodeFromUrl(string $url): ?string
    {
        // Pattern: /gol-ui/product/[product-name]--[product-code]
        if (preg_match('/\/gol-ui\/product\/[a-z0-9-]+--(\d+)/i', $url, $matches)) {
            return $matches[1];
        }

        // Pattern: /product/[name]-[product-code]
        if (preg_match('/\/product\/[a-z0-9-]+-(\d+)/i', $url, $matches)) {
            return $matches[1];
        }

        // Pattern: /shop/gb/groceries/[category]/[name]--[product-code]
        if (preg_match('/\/shop\/gb\/groceries\/[^\/]+\/[a-z0-9-]+--(\d+)/i', $url, $matches)) {
            return $matches[1];
        }

        // Fall back to using the full slug as identifier
        if (preg_match('/\/gol-ui\/product\/([a-z0-9-]+)/i', $url, $matches)) {
            return $matches[1];
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
