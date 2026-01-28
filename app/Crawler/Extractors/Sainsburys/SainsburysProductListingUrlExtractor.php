<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Sainsburys;

use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\BaseProductListingUrlExtractor;
use Generator;
use Symfony\Component\DomCrawler\Crawler;

class SainsburysProductListingUrlExtractor extends BaseProductListingUrlExtractor
{
    /**
     * Track processed URLs across all extraction methods.
     *
     * @var array<string>
     */
    private array $allProcessedUrls = [];

    public function canHandle(string $url): bool
    {
        return str_contains($url, 'sainsburys.co.uk');
    }

    protected function getProductLinkSelectors(): array
    {
        return [
            'a[href*="/product/"]',
        ];
    }

    protected function isProductUrl(string $url): bool
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

    protected function getRetailerSlug(): string
    {
        return 'sainsburys';
    }

    protected function supportsPagination(): bool
    {
        return true;
    }

    /**
     * Override to add inline JSON extraction after standard extraction.
     */
    public function extract(string $html, string $url): Generator
    {
        // Reset tracking for this extraction
        $this->allProcessedUrls = [];

        $crawler = new Crawler($html);

        if (! $this->shouldExtract($crawler, $html, $url)) {
            return;
        }

        // Standard product link extraction
        $productLinks = $this->extractProductLinks($crawler);

        foreach ($productLinks as $link) {
            if (! $link) {
                continue;
            }

            $normalizedUrl = $this->normalizeProductUrl($link, $url);

            if (in_array($normalizedUrl, $this->allProcessedUrls)) {
                continue;
            }

            if ($this->isProductUrl($normalizedUrl)) {
                $this->allProcessedUrls[] = $normalizedUrl;

                $this->logDebug("Found product URL: {$normalizedUrl}");

                yield new ProductListingUrl(
                    url: $normalizedUrl,
                    retailer: $this->getRetailerSlug(),
                    category: $this->extractCategoryForProduct($normalizedUrl, $url),
                    metadata: $this->buildMetadata($link, $url),
                );
            }
        }

        // Also try to extract product IDs from inline JSON data (Sainsbury's uses JavaScript rendering)
        yield from $this->extractFromInlineJson($html, $url);

        $this->logInfo('Extracted '.count($this->allProcessedUrls)." product listing URLs from {$url}");

        // Extract pagination
        if ($this->supportsPagination()) {
            yield from $this->extractPagination($crawler, $url);
        }
    }

    /**
     * Extract product URLs from inline JavaScript/JSON data.
     */
    private function extractFromInlineJson(string $html, string $baseUrl): Generator
    {
        // Look for product IDs in JSON data structures
        // Sainsbury's often embeds product data in script tags
        if (preg_match_all('/"product_uid"\s*:\s*"(\d+)"/', $html, $matches)) {
            foreach ($matches[1] as $productId) {
                // We don't have the full URL slug, but we can note the product ID
                // These would need to be resolved separately
                $this->logDebug("Found product UID in JSON: {$productId}");
            }
        }

        // Look for full product URLs in JSON
        if (preg_match_all('/"url"\s*:\s*"([^"]*\/product\/[^"]+)"/', $html, $matches)) {
            foreach ($matches[1] as $productPath) {
                $productPath = stripslashes($productPath);
                if ($this->isProductUrl($productPath)) {
                    $productUrl = $this->normalizeUrl($productPath, $baseUrl);

                    if (! in_array($productUrl, $this->allProcessedUrls)) {
                        $this->allProcessedUrls[] = $productUrl;

                        $this->logDebug("Found product URL from JSON: {$productUrl}");

                        yield new ProductListingUrl(
                            url: $productUrl,
                            retailer: $this->getRetailerSlug(),
                            category: $this->extractCategoryForProduct($productUrl, $baseUrl),
                            metadata: array_merge(
                                $this->buildMetadata($productPath, $baseUrl),
                                ['source' => 'inline-json']
                            ),
                        );
                    }
                }
            }
        }

        // Look for product links in data attributes
        if (preg_match_all('/data-product-url="([^"]+)"/', $html, $matches)) {
            foreach ($matches[1] as $productPath) {
                if ($this->isProductUrl($productPath)) {
                    $productUrl = $this->normalizeUrl($productPath, $baseUrl);

                    if (! in_array($productUrl, $this->allProcessedUrls)) {
                        $this->allProcessedUrls[] = $productUrl;

                        $this->logDebug("Found product URL from data attribute: {$productUrl}");

                        yield new ProductListingUrl(
                            url: $productUrl,
                            retailer: $this->getRetailerSlug(),
                            category: $this->extractCategoryForProduct($productUrl, $baseUrl),
                            metadata: array_merge(
                                $this->buildMetadata($productPath, $baseUrl),
                                ['source' => 'data-attribute']
                            ),
                        );
                    }
                }
            }
        }
    }

    /**
     * Extract category from the source URL path.
     * Sainsburys uses its own category extraction patterns specific to their URL structure.
     */
    protected function extractCategory(string $url): ?string
    {
        // Extract category from the source URL path - Sainsburys-specific patterns first
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
            $type = isset($matches[2]) && $matches[2] !== '' ? strtolower($matches[2]) : null;

            return $type ? "{$animal}-{$type}" : $animal;
        }

        // Fall back to CategoryExtractor
        return parent::extractCategory($url);
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
}
