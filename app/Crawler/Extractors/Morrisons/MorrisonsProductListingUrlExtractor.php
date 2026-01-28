<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Morrisons;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\Concerns\NormalizesUrls;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class MorrisonsProductListingUrlExtractor implements ExtractorInterface
{
    use NormalizesUrls;

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

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

                Log::debug("MorrisonsProductListingUrlExtractor: Found product URL: {$normalizedUrl}");

                yield new ProductListingUrl(
                    url: $normalizedUrl,
                    retailer: 'morrisons',
                    category: $this->extractCategory($url),
                    metadata: [
                        'discovered_from' => $url,
                        'discovered_at' => now()->toIso8601String(),
                    ]
                );
            }
        }

        Log::info('MorrisonsProductListingUrlExtractor: Extracted '.count($processedUrls)." product listing URLs from {$url}");
    }

    public function canHandle(string $url): bool
    {
        return str_contains($url, 'morrisons.com') && ! $this->isProductUrl($url);
    }

    private function isProductUrl(string $url): bool
    {
        // Morrisons product URLs: /products/[product-slug]/[SKU]
        // SKUs are numeric or alphanumeric identifiers
        return (bool) preg_match('/\/products\/[\w-]+\/\w+/', $url);
    }

    private function extractCategory(string $url): ?string
    {
        // Extract category from the source URL path
        // e.g., /browse/pet/dog -> "dog"
        if (preg_match('/\/browse\/pet\/([\w-]+)/', $url, $matches)) {
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
