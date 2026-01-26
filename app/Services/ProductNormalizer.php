<?php

declare(strict_types=1);

namespace App\Services;

class ProductNormalizer
{
    /**
     * Common brand name variations that should normalize to a standard form.
     *
     * @var array<string, string>
     */
    private const BRAND_ALIASES = [
        "lily's kitchen" => "Lily's Kitchen",
        'lilys kitchen' => "Lily's Kitchen",
        'james wellbeloved' => 'James Wellbeloved',
        'jameswellbeloved' => 'James Wellbeloved',
        'hills science diet' => "Hill's Science Diet",
        "hill's science diet" => "Hill's Science Diet",
        'hills' => "Hill's",
        "hill's" => "Hill's",
        'royal canin' => 'Royal Canin',
        'royalcanin' => 'Royal Canin',
        'purina pro plan' => 'Purina Pro Plan',
        'pro plan' => 'Purina Pro Plan',
    ];

    /**
     * Weight unit conversions to grams.
     *
     * @var array<string, float>
     */
    private const WEIGHT_TO_GRAMS = [
        'kg' => 1000.0,
        'g' => 1.0,
        'lb' => 453.592,
        'lbs' => 453.592,
        'oz' => 28.3495,
    ];

    /**
     * Normalize a product title for matching.
     *
     * Removes special characters, standardizes spacing, converts to lowercase.
     */
    public function normalizeTitle(string $title): string
    {
        // Convert to lowercase
        $normalized = mb_strtolower($title);

        // Remove common suffixes/prefixes that don't affect matching
        $removePhrases = [
            'with',
            'adult',
            'complete',
            'premium',
            'natural',
            'grain free',
            'grain-free',
        ];

        foreach ($removePhrases as $phrase) {
            $normalized = str_replace($phrase, '', $normalized);
        }

        // Extract and remove weight/quantity info (we match on this separately)
        $normalized = preg_replace('/\d+(?:\.\d+)?\s*(?:kg|g|lb|lbs|oz|ml|l)\b/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\d+\s*(?:x|pack)\s*\d*/i', '', $normalized) ?? $normalized;

        // Remove special characters except alphanumeric and spaces
        $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized) ?? $normalized;

        // Normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * Normalize a brand name to a standard form.
     */
    public function normalizeBrand(?string $brand): ?string
    {
        if ($brand === null || $brand === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($brand));

        // Check for known aliases
        if (isset(self::BRAND_ALIASES[$normalized])) {
            return self::BRAND_ALIASES[$normalized];
        }

        // Capitalize first letter of each word
        return ucwords($normalized);
    }

    /**
     * Extract weight in grams from a title string.
     *
     * @return int|null Weight in grams, or null if not found
     */
    public function extractWeightFromTitle(string $title): ?int
    {
        // Match patterns like "2kg", "400g", "15 kg", "2.5kg"
        if (preg_match('/(\d+(?:\.\d+)?)\s*(kg|g|lb|lbs|oz)\b/i', $title, $matches)) {
            $value = (float) $matches[1];
            $unit = strtolower($matches[2]);

            if (isset(self::WEIGHT_TO_GRAMS[$unit])) {
                return (int) round($value * self::WEIGHT_TO_GRAMS[$unit]);
            }
        }

        return null;
    }

    /**
     * Extract pack size (quantity) from a title string.
     *
     * @return int|null Pack size, or null if not found
     */
    public function extractQuantityFromTitle(string $title): ?int
    {
        // Match patterns like "12x400g", "6 x 400g", "12 pack"
        if (preg_match('/(\d+)\s*(?:x|pack)/i', $title, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Normalize weight value to grams.
     *
     * @param  int|float|null  $value  The weight value
     * @param  string|null  $unit  The unit (kg, g, lb, etc.)
     * @return int|null Weight in grams
     */
    public function normalizeWeight(int|float|null $value, ?string $unit = 'g'): ?int
    {
        if ($value === null) {
            return null;
        }

        $unit = strtolower($unit ?? 'g');

        if (isset(self::WEIGHT_TO_GRAMS[$unit])) {
            return (int) round($value * self::WEIGHT_TO_GRAMS[$unit]);
        }

        // Assume grams if unknown unit
        return (int) round($value);
    }

    /**
     * Compare two weights with a tolerance.
     *
     * @param  int|null  $weight1  First weight in grams
     * @param  int|null  $weight2  Second weight in grams
     * @param  float  $tolerance  Percentage tolerance (0.05 = 5%)
     */
    public function weightsMatch(?int $weight1, ?int $weight2, float $tolerance = 0.05): bool
    {
        if ($weight1 === null || $weight2 === null) {
            return false;
        }

        if ($weight1 === $weight2) {
            return true;
        }

        // Calculate percentage difference
        $diff = abs($weight1 - $weight2);
        $average = ($weight1 + $weight2) / 2;

        return ($diff / $average) <= $tolerance;
    }

    /**
     * Compare two brands for equality (case-insensitive, normalized).
     */
    public function brandsMatch(?string $brand1, ?string $brand2): bool
    {
        $normalized1 = $this->normalizeBrand($brand1);
        $normalized2 = $this->normalizeBrand($brand2);

        if ($normalized1 === null || $normalized2 === null) {
            return false;
        }

        return mb_strtolower($normalized1) === mb_strtolower($normalized2);
    }

    /**
     * Calculate string similarity using multiple algorithms and return the best score.
     *
     * @return float Similarity score between 0 and 100
     */
    public function calculateTitleSimilarity(string $title1, string $title2): float
    {
        $normalized1 = $this->normalizeTitle($title1);
        $normalized2 = $this->normalizeTitle($title2);

        // If normalized titles are identical
        if ($normalized1 === $normalized2) {
            return 100.0;
        }

        // Empty checks
        if ($normalized1 === '' || $normalized2 === '') {
            return 0.0;
        }

        // Calculate similar_text percentage
        similar_text($normalized1, $normalized2, $similarTextPercent);

        // Calculate Levenshtein-based similarity
        $maxLen = max(strlen($normalized1), strlen($normalized2));
        $levenshteinDistance = levenshtein($normalized1, $normalized2);
        $levenshteinSimilarity = (1 - ($levenshteinDistance / $maxLen)) * 100;

        // Return the higher of the two scores
        return max($similarTextPercent, $levenshteinSimilarity);
    }
}
