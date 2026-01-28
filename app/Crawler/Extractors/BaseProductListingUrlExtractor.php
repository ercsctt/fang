<?php

declare(strict_types=1);

namespace App\Crawler\Extractors;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\DTOs\PaginatedUrl;
use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\Concerns\ExtractsPagination;
use App\Crawler\Extractors\Concerns\NormalizesUrls;
use App\Crawler\Services\CategoryExtractor;
use Generator;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Base class for product listing URL extractors.
 *
 * Provides common extraction logic including:
 * - URL normalization via NormalizesUrls trait
 * - Optional pagination via ExtractsPagination trait
 * - Deduplication of extracted URLs
 * - Consistent logging
 *
 * Subclasses must implement:
 * - getProductLinkSelectors(): CSS selectors for finding product links
 * - isProductUrl(): Validate if a URL is a product URL
 * - getRetailerSlug(): Return the retailer identifier
 * - canHandle(): Check if extractor handles given URL
 */
abstract class BaseProductListingUrlExtractor implements ExtractorInterface
{
    use ExtractsPagination;
    use NormalizesUrls;

    public function __construct(
        protected readonly ?CategoryExtractor $categoryExtractor = null,
    ) {}

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);

        // Allow subclasses to perform pre-extraction checks (e.g., blocked page detection)
        if (! $this->shouldExtract($crawler, $html, $url)) {
            return;
        }

        $productLinks = $this->extractProductLinks($crawler);
        $processedUrls = [];

        foreach ($productLinks as $link) {
            if (! $link) {
                continue;
            }

            $normalizedUrl = $this->normalizeProductUrl($link, $url);

            if (in_array($normalizedUrl, $processedUrls)) {
                continue;
            }

            if ($this->isProductUrl($normalizedUrl)) {
                $processedUrls[] = $normalizedUrl;

                $this->logDebug("Found product URL: {$normalizedUrl}");

                yield new ProductListingUrl(
                    url: $normalizedUrl,
                    retailer: $this->getRetailerSlug(),
                    category: $this->extractCategoryForProduct($normalizedUrl, $url),
                    metadata: $this->buildMetadata($link, $url),
                );
            }
        }

        $this->logInfo('Extracted '.count($processedUrls)." product listing URLs from {$url}");

        // Extract pagination if this extractor supports it
        if ($this->supportsPagination()) {
            yield from $this->extractPagination($crawler, $url);
        }
    }

    /**
     * Get CSS selectors for finding product links.
     *
     * @return array<string>
     */
    abstract protected function getProductLinkSelectors(): array;

    /**
     * Check if a URL is a valid product URL for this retailer.
     */
    abstract protected function isProductUrl(string $url): bool;

    /**
     * Get the retailer slug used in ProductListingUrl DTOs.
     */
    abstract protected function getRetailerSlug(): string;

    /**
     * Check if this extractor supports pagination.
     * Override in subclasses that support pagination to return true.
     */
    protected function supportsPagination(): bool
    {
        return false;
    }

    /**
     * Pre-extraction check. Override to implement blocked page detection etc.
     */
    protected function shouldExtract(Crawler $crawler, string $html, string $url): bool
    {
        return true;
    }

    /**
     * Extract product links from the page using configured selectors.
     *
     * @return array<string|null>
     */
    protected function extractProductLinks(Crawler $crawler): array
    {
        $selectors = $this->getProductLinkSelectors();
        $links = [];

        foreach ($selectors as $selector) {
            try {
                $elements = $crawler->filter($selector);
                if ($elements->count() > 0) {
                    $elements->each(function (Crawler $node) use (&$links) {
                        $href = $node->attr('href');
                        if ($href !== null && ! in_array($href, $links)) {
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
     * Normalize a product URL. Override for retailer-specific normalization.
     */
    protected function normalizeProductUrl(string $link, string $baseUrl): string
    {
        return $this->normalizeUrl($link, $baseUrl);
    }

    /**
     * Extract category for a product URL.
     * By default, extracts from the source URL. Override for different behavior.
     */
    protected function extractCategoryForProduct(string $productUrl, string $sourceUrl): ?string
    {
        return $this->extractCategory($sourceUrl);
    }

    /**
     * Extract category from a URL using the CategoryExtractor.
     */
    protected function extractCategory(string $url): ?string
    {
        if ($this->categoryExtractor !== null) {
            return $this->categoryExtractor->extractFromUrl($url);
        }

        return null;
    }

    /**
     * Build metadata array for the ProductListingUrl DTO.
     * Override to add retailer-specific metadata.
     *
     * @return array<string, mixed>
     */
    protected function buildMetadata(string $originalLink, string $sourceUrl): array
    {
        return [
            'discovered_from' => $sourceUrl,
            'discovered_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Extract pagination URLs from the page.
     * Only called if supportsPagination() returns true.
     *
     * Uses string keys to avoid collision with product URL integer keys
     * when using `yield from` delegation.
     */
    protected function extractPagination(Crawler $crawler, string $url): Generator
    {
        $nextPageUrl = $this->findNextPageLink($crawler, $url);

        if ($nextPageUrl !== null) {
            $nextPage = $this->extractPageNumber($nextPageUrl, $url);

            $this->logDebug("Found next page URL: {$nextPageUrl} (page {$nextPage})");

            yield 'pagination' => new PaginatedUrl(
                url: $nextPageUrl,
                retailer: $this->getRetailerSlug(),
                page: $nextPage,
                category: $this->extractCategory($url),
                discoveredFrom: $url,
            );
        }
    }

    /**
     * Extract page number from a URL.
     * Override for retailer-specific page number extraction.
     */
    protected function extractPageNumber(string $nextPageUrl, string $currentUrl): int
    {
        // Try to get next page number from URL
        if (preg_match('/[?&]page=(\d+)/i', $nextPageUrl, $matches)) {
            return (int) $matches[1];
        }

        // Fallback: current page + 1
        return $this->extractCurrentPageNumber($currentUrl) + 1;
    }

    /**
     * Normalize a pagination URL (required by ExtractsPagination trait).
     */
    protected function normalizePageUrl(string $href, string $baseUrl): string
    {
        return $this->normalizeUrl($href, $baseUrl);
    }

    /**
     * Log a debug message with the extractor class name prefix.
     */
    protected function logDebug(string $message): void
    {
        Log::debug($this->getLogPrefix().': '.$message);
    }

    /**
     * Log an info message with the extractor class name prefix.
     */
    protected function logInfo(string $message): void
    {
        Log::info($this->getLogPrefix().': '.$message);
    }

    /**
     * Log a warning message with the extractor class name prefix.
     */
    protected function logWarning(string $message): void
    {
        Log::warning($this->getLogPrefix().': '.$message);
    }

    /**
     * Get the log prefix (class name by default).
     */
    protected function getLogPrefix(): string
    {
        return class_basename(static::class);
    }
}
