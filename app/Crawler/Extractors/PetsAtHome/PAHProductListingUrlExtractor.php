<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\PetsAtHome;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\PaginatedUrl;
use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\Concerns\ExtractsPagination;
use App\Crawler\Extractors\Concerns\NormalizesUrls;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class PAHProductListingUrlExtractor implements ExtractorInterface
{
    use ExtractsPagination;
    use NormalizesUrls;

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Pets at Home uses /product/ URLs with format: /product/product-name-slug/PRODUCTCODE
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

                Log::debug("PAHProductListingUrlExtractor: Found product URL: {$normalizedUrl}");

                yield new ProductListingUrl(
                    url: $normalizedUrl,
                    retailer: 'pets-at-home',
                    category: $this->extractCategory($url),
                    metadata: [
                        'discovered_from' => $url,
                        'discovered_at' => now()->toIso8601String(),
                    ]
                );
            }
        }

        Log::info('PAHProductListingUrlExtractor: Extracted '.count($processedUrls)." product listing URLs from {$url}");

        // Extract pagination
        $nextPageUrl = $this->findNextPageLink($crawler, $url);
        if ($nextPageUrl !== null) {
            $nextPage = $this->extractPageNumberFromUrl($nextPageUrl);
            Log::debug("PAHProductListingUrlExtractor: Found next page URL: {$nextPageUrl} (page {$nextPage})");

            yield new PaginatedUrl(
                url: $nextPageUrl,
                retailer: 'pets-at-home',
                page: $nextPage,
                category: $this->extractCategory($url),
                discoveredFrom: $url,
            );
        }
    }

    public function canHandle(string $url): bool
    {
        return str_contains($url, 'petsathome.com');
    }

    private function isProductUrl(string $url): bool
    {
        // Pets at Home product URLs: /product/product-name-slug/PRODUCTCODE
        // Product codes can be like P71341 or 7136893P
        return (bool) preg_match('/\/product\/[a-z0-9-]+\/[A-Z0-9]+$/i', $url);
    }

    private function extractCategory(string $url): ?string
    {
        // Extract category from the source URL path
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

        return null;
    }

    /**
     * Normalize a pagination URL to absolute form.
     */
    protected function normalizePageUrl(string $href, string $baseUrl): string
    {
        return $this->normalizeUrl($href, $baseUrl);
    }

    /**
     * Extract page number from a pagination URL.
     */
    private function extractPageNumberFromUrl(string $url): int
    {
        if (preg_match('/[?&]page=(\d+)/i', $url, $matches)) {
            return (int) $matches[1];
        }

        return 2; // Default to page 2 if we can't parse
    }
}
