<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Ocado;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\PaginatedUrl;
use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\Concerns\ExtractsPagination;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class OcadoProductListingUrlExtractor implements ExtractorInterface
{
    use ExtractsPagination;

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Check for blocked/captcha page
        if ($this->isBlockedPage($crawler, $html)) {
            Log::warning("OcadoProductListingUrlExtractor: Blocked/CAPTCHA page detected at {$url}");

            return;
        }

        // Ocado product URLs typically have format: /products/{product-slug}-{sku}
        // or /productCard/{sku}/{slug}
        $productLinks = $crawler->filter('a[href*="/products/"]')
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

                Log::debug("OcadoProductListingUrlExtractor: Found product URL: {$normalizedUrl}");

                yield new ProductListingUrl(
                    url: $normalizedUrl,
                    retailer: 'ocado',
                    category: $this->extractCategory($url),
                    metadata: [
                        'discovered_from' => $url,
                        'discovered_at' => now()->toIso8601String(),
                    ]
                );
            }
        }

        Log::info('OcadoProductListingUrlExtractor: Extracted '.count($processedUrls)." product listing URLs from {$url}");

        // Extract next page URL if available
        $nextPageUrl = $this->findNextPageLink($crawler, $url);
        if ($nextPageUrl !== null) {
            $currentPage = $this->extractCurrentPageNumber($url);
            yield new PaginatedUrl(
                url: $nextPageUrl,
                retailer: 'ocado',
                page: $currentPage + 1,
                category: $this->extractCategory($url),
                discoveredFrom: $url,
            );
        }
    }

    public function canHandle(string $url): bool
    {
        // Handle category/browse pages, not product pages
        if (! str_contains($url, 'ocado.com')) {
            return false;
        }

        // Category pages use /browse/ or /search/ patterns
        return str_contains($url, '/browse/') || str_contains($url, '/search/');
    }

    /**
     * Check if the page is blocked or shows a CAPTCHA.
     */
    private function isBlockedPage(Crawler $crawler, string $html): bool
    {
        if (str_contains(strtolower($html), 'captcha') || str_contains(strtolower($html), 'robot check')) {
            return true;
        }

        try {
            $title = $crawler->filter('title');
            if ($title->count() > 0) {
                $titleText = strtolower($title->text());
                if (str_contains($titleText, 'access denied') || str_contains($titleText, 'blocked')) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Continue
        }

        return false;
    }

    private function normalizeUrl(string $url, string $baseUrl): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? 'www.ocado.com';

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
        // Ocado product URLs: /products/{product-name-slug}-{sku}
        // SKU is typically a numeric ID at the end
        // Example: /products/royal-canin-mini-adult-dog-food-2kg-567890
        return (bool) preg_match('/\/products\/[a-z0-9-]+-\d+$/i', $url);
    }

    private function extractCategory(string $url): ?string
    {
        // Extract category from Ocado browse URL path
        // Example: /browse/pets-20974/dog-111797/dog-food-111800
        // We want to extract "dog-food"

        // First, try to find the category just before the product SKU segment
        if (preg_match('/\/(dog-food|dog-treats|puppy-food|puppy-treats|cat-food|cat-treats)(?:-\d+)?(?:\/|$)/i', $url, $matches)) {
            return strtolower($matches[1]);
        }

        // Fall back to extracting from /dog/ or /cat/ segments
        if (preg_match('/\/(dog|cat|puppy|kitten)(?:-\d+)?(?:\/|$)/i', $url, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Override to extract page number from Ocado URL patterns.
     */
    protected function extractCurrentPageNumber(string $url): int
    {
        // Ocado uses ?page=N pattern
        if (preg_match('/[?&]page=(\d+)/i', $url, $matches)) {
            return (int) $matches[1];
        }

        return 1;
    }

    /**
     * Normalize a pagination URL (required by ExtractsPagination trait).
     */
    protected function normalizePageUrl(string $href, string $baseUrl): string
    {
        return $this->normalizeUrl($href, $baseUrl);
    }
}
