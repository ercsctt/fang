<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\PetsAtHome;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductListingUrl;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class PAHProductListingUrlExtractor implements ExtractorInterface
{
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
    }

    public function canHandle(string $url): bool
    {
        return str_contains($url, 'petsathome.com');
    }

    private function normalizeUrl(string $url, string $baseUrl): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? 'www.petsathome.com';

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
        // Pets at Home product URLs: /product/product-name-slug/PRODUCTCODE
        // Product codes can be like P71341 or 7136893P
        return (bool) preg_match('/\/product\/[a-z0-9-]+\/[A-Z0-9]+$/i', $url);
    }

    private function extractCategory(string $url): ?string
    {
        // Extract category from the source URL path
        // e.g., /shop/en/pets/dog/dog-food -> "dog-food"
        // e.g., /shop/en/pets/dog/dog-treats -> "dog-treats"
        if (preg_match('/\/(dog|cat|puppy|kitten)[-\/]?(food|treats|accessories)?/i', $url, $matches)) {
            $animal = strtolower($matches[1]);
            $type = isset($matches[2]) ? strtolower($matches[2]) : null;

            return $type ? "{$animal}-{$type}" : $animal;
        }

        return null;
    }
}
