<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Zooplus;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductListingUrl;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ZooplusProductListingUrlExtractor implements ExtractorInterface
{
    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Zooplus uses various patterns for product URLs
        // /shop/dogs/dry_dog_food/{product-slug}_{product-id}
        $productLinks = $crawler->filter('a[href*="/shop/dogs/"]')
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

                Log::debug("ZooplusProductListingUrlExtractor: Found product URL: {$normalizedUrl}");

                yield new ProductListingUrl(
                    url: $normalizedUrl,
                    retailer: 'zooplus-uk',
                    category: $this->extractCategory($url),
                    metadata: [
                        'discovered_from' => $url,
                        'discovered_at' => now()->toIso8601String(),
                    ]
                );
            }
        }

        Log::info('ZooplusProductListingUrlExtractor: Extracted '.count($processedUrls)." product listing URLs from {$url}");
    }

    public function canHandle(string $url): bool
    {
        // Only handle category/listing pages, not product pages
        return str_contains($url, 'zooplus.co.uk')
            && ! $this->isProductUrl($url);
    }

    private function normalizeUrl(string $url, string $baseUrl): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? 'www.zooplus.co.uk';

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
        // Zooplus product URLs end with a numeric ID after an underscore
        // Example: /shop/dogs/dry_dog_food/brand/product-name_123456
        // Category URLs don't have numeric IDs at the end
        return (bool) preg_match('/\/shop\/dogs\/[a-z0-9_\/]+\/[a-z0-9-]+_(\d{4,})/i', $url);
    }

    private function extractCategory(string $url): ?string
    {
        // Extract category from the source URL path
        // e.g., /shop/dogs/dry_dog_food -> "dry_dog_food"
        if (preg_match('/\/shop\/dogs\/([\w_]+)/', $url, $matches)) {
            return $matches[1];
        }

        // Broader category extraction
        if (preg_match('/\/(dog|puppy)[_\/]?(food|treats|chews)?/i', $url, $matches)) {
            $animal = strtolower($matches[1]);
            $type = isset($matches[2]) ? strtolower($matches[2]) : null;

            return $type ? "{$animal}_{$type}" : $animal;
        }

        return null;
    }
}
