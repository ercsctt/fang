<?php

declare(strict_types=1);

namespace App\Crawler\Extractors\Concerns;

use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Provides JSON-LD structured data extraction functionality.
 *
 * This trait consolidates the duplicate extractJsonLd() method found across
 * ProductDetailsExtractor classes, which extract Product schema data from
 * script[type='application/ld+json'] tags on retail websites.
 */
trait ExtractsJsonLd
{
    /**
     * Extract JSON-LD structured data from the page.
     *
     * Searches for script[type='application/ld+json'] tags and extracts
     * Product schema data. Handles both @graph format (where Product is
     * nested in a graph array) and direct Product type format.
     *
     * @param  Crawler  $crawler  The DOM crawler for the page
     * @return array<string, mixed> The Product structured data, or empty array if not found
     */
    protected function extractJsonLd(Crawler $crawler): array
    {
        try {
            $scripts = $crawler->filter('script[type="application/ld+json"]');

            foreach ($scripts as $script) {
                $content = $script->textContent;
                $data = json_decode($content, true);

                if ($data === null) {
                    continue;
                }

                // Handle @graph format
                if (isset($data['@graph'])) {
                    foreach ($data['@graph'] as $item) {
                        if (($item['@type'] ?? null) === 'Product') {
                            return $item;
                        }
                    }
                }

                // Direct Product type
                if (($data['@type'] ?? null) === 'Product') {
                    return $data;
                }
            }
        } catch (\Exception $e) {
            $this->logJsonLdError($e->getMessage());
        }

        return [];
    }

    /**
     * Log JSON-LD extraction errors.
     *
     * Override this method in your extractor class to customize logging
     * with extractor-specific prefixes or context.
     *
     * @param  string  $message  The error message to log
     */
    protected function logJsonLdError(string $message): void
    {
        $class = class_basename($this);
        Log::debug("{$class}: Failed to extract JSON-LD: {$message}");
    }
}
