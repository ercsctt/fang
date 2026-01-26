<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Tesco;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductListingUrl;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class TescoProductListingUrlExtractor implements ExtractorInterface
{
    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Tesco uses /groceries/en-GB/products/ for product URLs
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

                Log::debug("TescoProductListingUrlExtractor: Found product URL: {$normalizedUrl}");

                yield new ProductListingUrl(
                    url: $normalizedUrl,
                    retailer: 'tesco',
                    category: $this->extractCategory($url),
                    metadata: [
                        'discovered_from' => $url,
                        'discovered_at' => now()->toIso8601String(),
                    ]
                );
            }
        }

        Log::info('TescoProductListingUrlExtractor: Extracted '.count($processedUrls)." product listing URLs from {$url}");
    }

    public function canHandle(string $url): bool
    {
        return str_contains($url, 'tesco.com');
    }

    private function normalizeUrl(string $url, string $baseUrl): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? 'www.tesco.com';

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
        // Tesco product URLs: /groceries/en-GB/products/PRODUCTCODE
        // Product codes are numeric (TPNs - Tesco Product Numbers)
        return (bool) preg_match('/\/groceries\/en-GB\/products\/\d+/', $url);
    }

    private function extractCategory(string $url): ?string
    {
        // Extract category from the source URL path
        // e.g., /groceries/en-GB/shop/pets/dog-food-and-treats/ -> "dog-food-and-treats"
        if (preg_match('/\/shop\/pets?\/([\w-]+)/', $url, $matches)) {
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
}
