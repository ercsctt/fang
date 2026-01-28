<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Asda;

use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\BaseProductListingUrlExtractor;
use Generator;
use Symfony\Component\DomCrawler\Crawler;

class AsdaProductListingUrlExtractor extends BaseProductListingUrlExtractor
{
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

    protected function getProductLinkSelectors(): array
    {
        return [
            'a[href*="/product/"]',
            '[data-auto-id="linkProductDetail"]',
            '.co-product__anchor',
            '.product-tile a',
            '.product-card a[href*="/product/"]',
            '.listing-item a[href*="/product/"]',
        ];
    }

    protected function isProductUrl(string $url): bool
    {
        // Asda product URLs: /product/[product-name]/[SKU-ID] or /product/[SKU-ID]
        return $this->extractProductId($url) !== null;
    }

    protected function getRetailerSlug(): string
    {
        return 'asda';
    }

    /**
     * Override to use product ID-based deduplication and include script extraction.
     */
    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        if (! $this->shouldExtract($crawler, $html, $url)) {
            return;
        }

        // Get links from HTML and inline JSON
        $productLinks = $this->extractAllProductLinks($crawler);
        $processedIds = [];

        foreach ($productLinks as $link) {
            if (! $link) {
                continue;
            }

            $productId = $this->extractProductId($link);
            if ($productId === null) {
                continue;
            }

            // Skip duplicates (product ID-based deduplication)
            if (in_array($productId, $processedIds)) {
                continue;
            }

            $normalizedUrl = $this->normalizeAsdaProductUrl($link);

            $processedIds[] = $productId;

            $this->logDebug("Found product ID: {$productId}");

            yield new ProductListingUrl(
                url: $normalizedUrl,
                retailer: $this->getRetailerSlug(),
                category: $this->extractCategoryForProduct($normalizedUrl, $url),
                metadata: [
                    'product_id' => $productId,
                    'discovered_from' => $url,
                    'discovered_at' => now()->toIso8601String(),
                ],
            );
        }

        $this->logInfo('Extracted '.count($processedIds)." product URLs from {$url}");
    }

    /**
     * Extract all product links including from inline JSON in scripts.
     *
     * @return array<string>
     */
    private function extractAllProductLinks(Crawler $crawler): array
    {
        // Start with standard link extraction
        $links = $this->extractProductLinksWithFilter($crawler);

        // Also extract from JSON data embedded in scripts (Asda uses dynamic loading)
        $this->extractLinksFromScripts($crawler, $links);

        return $links;
    }

    /**
     * Extract product links from HTML using selectors, filtering for /product/.
     *
     * @return array<string>
     */
    private function extractProductLinksWithFilter(Crawler $crawler): array
    {
        $links = [];
        $selectors = $this->getProductLinkSelectors();

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
                $this->logDebug("Selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return $links;
    }

    /**
     * Extract product links from inline JSON in script tags.
     *
     * @param  array<string>  $links
     */
    private function extractLinksFromScripts(Crawler $crawler, array &$links): void
    {
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
            $this->logDebug("Script extraction failed: {$e->getMessage()}");
        }
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
     * Normalize Asda product URL to canonical form.
     */
    private function normalizeAsdaProductUrl(string $url): string
    {
        $normalizedUrl = $this->normalizeUrl($url, 'https://groceries.asda.com/');

        // Remove query parameters and fragments
        $cleanUrl = preg_replace('/[?#].*$/', '', $normalizedUrl);

        return $cleanUrl ?? $normalizedUrl;
    }
}
