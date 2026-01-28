<?php

declare(strict_types=1);

namespace App\Crawler\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Service for extracting product categories from URLs and DOM elements.
 *
 * This service consolidates category extraction logic that was previously
 * duplicated across multiple extractor classes.
 */
class CategoryExtractor
{
    /**
     * Extract category from URL using common patterns.
     *
     * Attempts to match URL patterns against configured category patterns
     * to identify the product category.
     */
    public function extractFromUrl(string $url): ?string
    {
        // Try retailer-specific structured paths first (most specific)
        // These have special meaning in the URL structure

        // Extract from aisle path (e.g., /aisle/pet-shop/dog/dog-food/)
        // Exclude numeric segments (product IDs)
        if (preg_match('/\/aisle\/((?:[^\/]+\/)*[^\/\d]+)(?:\/\d+)?(?:\/|$|\?)/i', $url, $matches)) {
            return $this->extractFromPath($matches[1]);
        }

        // Extract from shelf path
        if (preg_match('/\/shelf\/([^\/\?]+)/i', $url, $matches)) {
            return $this->normalizeCategory($matches[1]);
        }

        // Extract from super-department path
        if (preg_match('/\/super-department\/([^\/]+)/i', $url, $matches)) {
            return $this->normalizeCategory($matches[1]);
        }

        // Extract from search query (preserve encoding like '+')
        if (preg_match('/\/search\/([^\/\?]+)/i', $url, $matches)) {
            return $matches[1];
        }

        // Extract from gol-ui path (Sainsbury's specific)
        if (preg_match('/\/gol-ui\/[^\/]+\/([\w-]+)/i', $url, $matches)) {
            return $this->normalizeCategory($matches[1]);
        }

        // Extract from Ocado browse URL (e.g., /browse/pets-20974/dog-111797/dog-food-111800)
        if (preg_match('/\/browse\/.*?\/(dog-food|dog-treats|puppy-food|puppy-treats|cat-food|cat-treats)(?:-\d+)?(?:\/|$)/i', $url, $matches)) {
            return strtolower($matches[1]);
        }

        // Extract from /dog/food/ or /cat/food/ or /dog/treats/ style paths
        if (preg_match('/\/(dog|puppy|cat|kitten)\/(food|treats)(?:\/|$)/i', $url, $matches)) {
            $animal = strtolower($matches[1]);
            $type = strtolower($matches[2]);

            // Map puppy/kitten to dog/cat
            if ($animal === 'puppy') {
                $animal = 'dog';
            }
            if ($animal === 'kitten') {
                $animal = 'cat';
            }

            return "{$animal}-{$type}";
        }

        // Extract from /pets/.../dog-food or /pets/.../dog/food style paths
        if (preg_match('/\/pets?\/(?:[^\/]+\/)*?(dog|puppy|cat|kitten)[-\/](food|treats)(?:\/|$|\?)/i', $url, $matches)) {
            $animal = strtolower($matches[1]);
            $type = strtolower($matches[2]);

            if ($animal === 'puppy') {
                $animal = 'dog';
            }
            if ($animal === 'kitten') {
                $animal = 'cat';
            }

            return "{$animal}-{$type}";
        }

        // Extract from general pet category paths
        // First check if it matches any config patterns (e.g., puppy-food -> dog-food)
        if (preg_match('/\/pets?\/([\w-]+)/i', $url, $matches)) {
            $category = $matches[1];

            // Check if this category or its variants match a config pattern
            // Match the whole category string, not just a substring
            $patterns = config('crawler.category_patterns', []);
            foreach ($patterns as $configCategory => $regexList) {
                foreach ($regexList as $regex) {
                    // Extract the pattern without delimiters and modifiers
                    // e.g., '/dog-food/i' -> 'dog-food'
                    $pattern = preg_replace('/^\/(.+)\/[a-z]*$/', '$1', $regex);
                    // Create a new anchored regex
                    $anchoredRegex = '/^'.$pattern.'$/i';
                    if (preg_match($anchoredRegex, $category)) {
                        return $configCategory;
                    }
                }
            }

            // If not found in config patterns, normalize the category
            return $this->normalizeCategory($category);
        }

        // Try specific category patterns (fallback for other URL structures)
        // These match exact product categories like 'dog-food', 'cat-treats', etc.
        $patterns = config('crawler.category_patterns', []);

        foreach ($patterns as $category => $regexList) {
            foreach ($regexList as $regex) {
                if (preg_match($regex, $url)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * Extract category from breadcrumb navigation elements.
     *
     * @param  Crawler  $crawler  The DOM crawler instance
     * @param  array<string>  $selectors  CSS selectors for breadcrumb links
     * @param  int  $depthFromEnd  How many levels from the end to extract (0 = last, 1 = second-to-last)
     */
    public function extractFromBreadcrumbs(
        Crawler $crawler,
        array $selectors = [],
        int $depthFromEnd = 1
    ): ?string {
        // Default breadcrumb selectors if none provided
        if (empty($selectors)) {
            $selectors = [
                '#wayfinding-breadcrumbs_feature_div a',
                '.a-breadcrumb a',
                '.breadcrumb a',
                '.breadcrumbs a',
                '[data-auto="breadcrumb"] a',
                'nav.breadcrumb a',
                '.beans-breadcrumb a',
            ];
        }

        foreach ($selectors as $selector) {
            try {
                $elements = $crawler->filter($selector);
                if ($elements->count() > 1) {
                    $crumbs = $elements->each(fn (Crawler $node) => trim($node->text()));
                    $crumbs = array_filter($crumbs);
                    $crumbs = array_values($crumbs);

                    if (count($crumbs) >= 2) {
                        // Calculate index based on depth from end
                        $categoryIndex = max(0, count($crumbs) - 1 - $depthFromEnd);
                        $category = $crumbs[$categoryIndex];

                        // If the category at the desired depth is generic, try the last breadcrumb
                        if ($this->isGenericTerm($category) && $categoryIndex < count($crumbs) - 1) {
                            $category = $crumbs[count($crumbs) - 1];
                        }

                        // Skip generic terms
                        if (! $this->isGenericTerm($category)) {
                            return $category;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::debug("CategoryExtractor: Breadcrumb selector {$selector} failed: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Extract category from a path string (e.g., "pet-shop/dog/dog-food").
     */
    private function extractFromPath(string $path): ?string
    {
        $parts = explode('/', $path);

        // Return the most specific category (last meaningful part)
        $filteredParts = array_filter($parts, fn ($part) => ! empty($part) && ! $this->isGenericTerm($part));

        if (! empty($filteredParts)) {
            $lastPart = end($filteredParts);

            return $this->normalizeCategory($lastPart);
        }

        return null;
    }

    /**
     * Normalize a category string (replace hyphens with spaces, lowercase).
     */
    private function normalizeCategory(string $category): string
    {
        return str_replace('-', ' ', $category);
    }

    /**
     * Check if a term is too generic to be a useful category.
     */
    private function isGenericTerm(string $term): bool
    {
        $genericTerms = config('crawler.category_filters', []);

        return in_array(strtolower($term), $genericTerms);
    }
}
