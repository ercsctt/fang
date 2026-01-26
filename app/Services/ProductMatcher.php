<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MatchType;
use App\Models\Product;
use App\Models\ProductListing;
use App\Models\ProductListingMatch;
use Illuminate\Support\Facades\Log;

class ProductMatcher
{
    /**
     * Minimum confidence score for an exact match.
     */
    private const EXACT_MATCH_THRESHOLD = 95.0;

    /**
     * Minimum confidence score for a fuzzy match.
     */
    private const FUZZY_MATCH_THRESHOLD = 70.0;

    public function __construct(
        private readonly ProductNormalizer $normalizer,
    ) {}

    /**
     * Attempt to match a ProductListing to a canonical Product.
     *
     * Returns the created/updated match record, or null if no suitable match found
     * and createProductIfNoMatch is false.
     */
    public function match(ProductListing $listing, bool $createProductIfNoMatch = true): ?ProductListingMatch
    {
        // Check if already matched
        $existingMatch = ProductListingMatch::query()
            ->where('product_listing_id', $listing->id)
            ->first();

        if ($existingMatch) {
            Log::debug('ProductListing already matched', [
                'listing_id' => $listing->id,
                'product_id' => $existingMatch->product_id,
            ]);

            return $existingMatch;
        }

        // Try exact match first
        $exactMatch = $this->findExactMatch($listing);

        if ($exactMatch !== null) {
            return $this->createMatch(
                listing: $listing,
                product: $exactMatch['product'],
                confidence: $exactMatch['confidence'],
                type: MatchType::Exact,
            );
        }

        // Try fuzzy match
        $fuzzyMatch = $this->findFuzzyMatch($listing);

        if ($fuzzyMatch !== null) {
            // Use confidence to determine match type
            $matchType = $fuzzyMatch['confidence'] >= self::EXACT_MATCH_THRESHOLD
                ? MatchType::Exact
                : MatchType::Fuzzy;

            return $this->createMatch(
                listing: $listing,
                product: $fuzzyMatch['product'],
                confidence: $fuzzyMatch['confidence'],
                type: $matchType,
            );
        }

        // No match found - create new product if requested
        if ($createProductIfNoMatch) {
            $product = $this->createProductFromListing($listing);

            return $this->createMatch(
                listing: $listing,
                product: $product,
                confidence: 100.0,
                type: MatchType::Exact,
            );
        }

        return null;
    }

    /**
     * Find an exact match: same brand + normalized name + weight/quantity.
     *
     * @return array{product: Product, confidence: float}|null
     */
    public function findExactMatch(ProductListing $listing): ?array
    {
        if ($listing->brand === null || $listing->title === null) {
            return null;
        }

        $normalizedBrand = $this->normalizer->normalizeBrand($listing->brand);
        $normalizedTitle = $this->normalizer->normalizeTitle($listing->title);

        // Query products with same normalized brand
        $candidates = Product::query()
            ->whereNotNull('brand')
            ->get();

        $bestMatch = null;
        $bestConfidence = 0.0;

        foreach ($candidates as $product) {
            // Check brand match
            if (! $this->normalizer->brandsMatch($listing->brand, $product->brand)) {
                continue;
            }

            // Check title similarity
            $titleSimilarity = $this->normalizer->calculateTitleSimilarity(
                $listing->title ?? '',
                $product->name ?? ''
            );

            if ($titleSimilarity < self::EXACT_MATCH_THRESHOLD) {
                continue;
            }

            // Check weight match (if available)
            if ($listing->weight_grams !== null && $product->weight_grams !== null) {
                if (! $this->normalizer->weightsMatch($listing->weight_grams, $product->weight_grams)) {
                    continue;
                }
            }

            // Check quantity match (if available)
            if ($listing->quantity !== null && $product->quantity !== null) {
                if ($listing->quantity !== $product->quantity) {
                    continue;
                }
            }

            $confidence = $this->calculateConfidence(
                listing: $listing,
                product: $product,
                titleSimilarity: $titleSimilarity,
            );

            if ($confidence >= self::EXACT_MATCH_THRESHOLD && $confidence > $bestConfidence) {
                $bestMatch = $product;
                $bestConfidence = $confidence;
            }
        }

        if ($bestMatch !== null) {
            return [
                'product' => $bestMatch,
                'confidence' => $bestConfidence,
            ];
        }

        return null;
    }

    /**
     * Find a fuzzy match using title similarity algorithms.
     *
     * @return array{product: Product, confidence: float}|null
     */
    public function findFuzzyMatch(ProductListing $listing): ?array
    {
        if ($listing->title === null) {
            return null;
        }

        $bestMatch = null;
        $bestConfidence = 0.0;

        // Query all products (in production, this should be optimized)
        $candidates = Product::query()->get();

        foreach ($candidates as $product) {
            // Calculate title similarity
            $titleSimilarity = $this->normalizer->calculateTitleSimilarity(
                $listing->title ?? '',
                $product->name ?? ''
            );

            // Skip if title similarity is too low
            if ($titleSimilarity < self::FUZZY_MATCH_THRESHOLD) {
                continue;
            }

            // Calculate overall confidence
            $confidence = $this->calculateConfidence(
                listing: $listing,
                product: $product,
                titleSimilarity: $titleSimilarity,
            );

            if ($confidence >= self::FUZZY_MATCH_THRESHOLD && $confidence > $bestConfidence) {
                $bestMatch = $product;
                $bestConfidence = $confidence;
            }
        }

        if ($bestMatch !== null && $bestConfidence >= self::FUZZY_MATCH_THRESHOLD) {
            return [
                'product' => $bestMatch,
                'confidence' => $bestConfidence,
            ];
        }

        return null;
    }

    /**
     * Calculate the overall confidence score for a potential match.
     *
     * @param  float  $titleSimilarity  Pre-calculated title similarity (0-100)
     */
    public function calculateConfidence(
        ProductListing $listing,
        Product $product,
        float $titleSimilarity,
    ): float {
        // Base score from title similarity (weighted 50%)
        $score = $titleSimilarity * 0.5;

        // Brand match bonus (weighted 25%)
        if ($this->normalizer->brandsMatch($listing->brand, $product->brand)) {
            $score += 25.0;
        } elseif ($listing->brand === null || $product->brand === null) {
            // No penalty if one is missing
            $score += 12.5;
        }

        // Weight match bonus (weighted 15%)
        if ($listing->weight_grams !== null && $product->weight_grams !== null) {
            if ($this->normalizer->weightsMatch($listing->weight_grams, $product->weight_grams)) {
                $score += 15.0;
            }
        } else {
            // No penalty if weight unavailable
            $score += 7.5;
        }

        // Quantity match bonus (weighted 10%)
        if ($listing->quantity !== null && $product->quantity !== null) {
            if ($listing->quantity === $product->quantity) {
                $score += 10.0;
            }
        } else {
            // No penalty if quantity unavailable
            $score += 5.0;
        }

        return min(100.0, $score);
    }

    /**
     * Create a ProductListingMatch record.
     */
    private function createMatch(
        ProductListing $listing,
        Product $product,
        float $confidence,
        MatchType $type,
    ): ProductListingMatch {
        $match = ProductListingMatch::query()->create([
            'product_id' => $product->id,
            'product_listing_id' => $listing->id,
            'confidence_score' => $confidence,
            'match_type' => $type,
            'matched_at' => now(),
        ]);

        Log::info('ProductListingMatch created', [
            'match_id' => $match->id,
            'product_id' => $product->id,
            'listing_id' => $listing->id,
            'confidence' => $confidence,
            'type' => $type->value,
        ]);

        // Update product price statistics
        $this->updateProductPriceStatistics($product);

        return $match;
    }

    /**
     * Create a new Product from a ProductListing.
     */
    private function createProductFromListing(ProductListing $listing): Product
    {
        $normalizedBrand = $this->normalizer->normalizeBrand($listing->brand);

        $product = Product::query()->create([
            'name' => $listing->title,
            'brand' => $normalizedBrand,
            'description' => $listing->description,
            'category' => $listing->category ?? 'Dog Food',
            'weight_grams' => $listing->weight_grams,
            'quantity' => $listing->quantity,
            'primary_image' => $listing->images[0] ?? null,
            'lowest_price_pence' => $listing->price_pence,
            'average_price_pence' => $listing->price_pence,
            'is_verified' => false,
        ]);

        Log::info('New Product created from listing', [
            'product_id' => $product->id,
            'listing_id' => $listing->id,
            'name' => $product->name,
        ]);

        return $product;
    }

    /**
     * Update a Product's price statistics based on linked listings.
     */
    private function updateProductPriceStatistics(Product $product): void
    {
        $prices = ProductListing::query()
            ->whereIn('id', function ($query) use ($product) {
                $query->select('product_listing_id')
                    ->from('product_listing_matches')
                    ->where('product_id', $product->id);
            })
            ->whereNotNull('price_pence')
            ->where('in_stock', true)
            ->pluck('price_pence');

        if ($prices->isEmpty()) {
            return;
        }

        $product->update([
            'lowest_price_pence' => $prices->min(),
            'average_price_pence' => (int) round($prices->avg()),
        ]);
    }
}
