<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Waitrose;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\PaginatedUrl;
use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\Concerns\ExtractsPagination;
use App\Crawler\Extractors\Concerns\NormalizesUrls;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class WaitroseProductListingUrlExtractor implements ExtractorInterface
{
    use ExtractsPagination;
    use NormalizesUrls;

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Waitrose uses /ecom/products/ for product URLs
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

                Log::debug("WaitroseProductListingUrlExtractor: Found product URL: {$normalizedUrl}");

                yield new ProductListingUrl(
                    url: $normalizedUrl,
                    retailer: 'waitrose',
                    category: $this->extractCategory($url),
                    metadata: [
                        'discovered_from' => $url,
                        'discovered_at' => now()->toIso8601String(),
                    ]
                );
            }
        }

        Log::info('WaitroseProductListingUrlExtractor: Extracted '.count($processedUrls)." product listing URLs from {$url}");

        // Extract next page URL if available
        $nextPageUrl = $this->findNextPageLink($crawler, $url);
        if ($nextPageUrl !== null) {
            $currentPage = $this->extractCurrentPageNumber($url);
            yield new PaginatedUrl(
                url: $nextPageUrl,
                retailer: 'waitrose',
                page: $currentPage + 1,
                category: $this->extractCategory($url),
                discoveredFrom: $url,
            );
        }
    }

    public function canHandle(string $url): bool
    {
        return str_contains($url, 'waitrose.com');
    }

    private function isProductUrl(string $url): bool
    {
        // Waitrose product URLs: /ecom/products/{product-slug}/{product-id}
        // Product IDs are alphanumeric
        return (bool) preg_match('/\/ecom\/products\/[a-z0-9-]+\/[a-z0-9-]+/i', $url);
    }

    private function extractCategory(string $url): ?string
    {
        // Extract category from the source URL path
        // e.g., /ecom/shop/browse/groceries/pet/dog/dog_food -> "dog_food"
        if (preg_match('/\/pet\/dog\/([\w_]+)/', $url, $matches)) {
            return $matches[1];
        }

        // Broader pet category extraction
        if (preg_match('/\/(dog|cat|puppy|kitten)[_\/]?(food|treats)?/i', $url, $matches)) {
            $animal = strtolower($matches[1]);
            $type = isset($matches[2]) ? strtolower($matches[2]) : null;

            return $type ? "{$animal}_{$type}" : $animal;
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
