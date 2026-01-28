<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\BestTimeToBuy;
use App\DTOs\CategoryPriceReport;
use App\DTOs\PricePattern;
use App\DTOs\PriceStatistics;
use App\DTOs\PriceTrend;
use App\Enums\CanonicalCategory;
use App\Enums\PriceTrendIndicator;
use App\Models\Product;
use App\Models\ProductListing;
use App\Models\ProductListingPrice;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PriceAnalytics
{
    /**
     * Threshold percentage for considering a price as "stable".
     */
    private const STABLE_THRESHOLD_PERCENT = 2.0;

    /**
     * Threshold for high volatility (standard deviation as percentage of mean).
     */
    private const VOLATILITY_THRESHOLD_PERCENT = 15.0;

    /**
     * Minimum discount percentage to consider a price as "on sale".
     */
    private const SALE_THRESHOLD_PERCENT = 5.0;

    /**
     * Calculate average price over a time period for a product listing.
     */
    public function getAveragePrice(ProductListing $listing, int $days): ?int
    {
        $startDate = now()->subDays($days);

        $average = ProductListingPrice::query()
            ->where('product_listing_id', $listing->id)
            ->where('recorded_at', '>=', $startDate)
            ->avg('price_pence');

        return $average !== null ? (int) round($average) : null;
    }

    /**
     * Calculate price statistics for a product listing over a time period.
     */
    public function getPriceStatistics(ProductListing $listing, int $days): ?PriceStatistics
    {
        $startDate = now()->subDays($days);

        $prices = ProductListingPrice::query()
            ->where('product_listing_id', $listing->id)
            ->where('recorded_at', '>=', $startDate)
            ->pluck('price_pence');

        if ($prices->isEmpty()) {
            return null;
        }

        $average = (int) round($prices->avg());
        $min = $prices->min();
        $max = $prices->max();
        $range = $max - $min;
        $stdDev = $this->calculateStandardDeviation($prices, $average);

        return new PriceStatistics(
            averagePricePence: $average,
            minPricePence: $min,
            maxPricePence: $max,
            priceRangePence: $range,
            standardDeviation: $stdDev,
            dataPointCount: $prices->count(),
            periodDays: $days,
        );
    }

    /**
     * Get price statistics for multiple time periods (7d, 30d, 90d).
     *
     * @return array<string, PriceStatistics|null>
     */
    public function getMultiPeriodStatistics(ProductListing $listing): array
    {
        return [
            '7d' => $this->getPriceStatistics($listing, 7),
            '30d' => $this->getPriceStatistics($listing, 30),
            '90d' => $this->getPriceStatistics($listing, 90),
        ];
    }

    /**
     * Calculate the price trend for a product listing over a period.
     */
    public function getPriceTrend(ProductListing $listing, int $days): ?PriceTrend
    {
        $startDate = now()->subDays($days);

        $firstPrice = ProductListingPrice::query()
            ->where('product_listing_id', $listing->id)
            ->where('recorded_at', '>=', $startDate)
            ->orderBy('recorded_at', 'asc')
            ->first();

        $lastPrice = ProductListingPrice::query()
            ->where('product_listing_id', $listing->id)
            ->where('recorded_at', '>=', $startDate)
            ->orderBy('recorded_at', 'desc')
            ->first();

        if ($firstPrice === null || $lastPrice === null) {
            return null;
        }

        $changePercentage = $this->calculatePriceChangePercentage(
            $firstPrice->price_pence,
            $lastPrice->price_pence
        );

        $statistics = $this->getPriceStatistics($listing, $days);
        $indicator = $this->determineTrendIndicator($changePercentage, $statistics);

        return new PriceTrend(
            indicator: $indicator,
            changePercentage: $changePercentage,
            startPricePence: $firstPrice->price_pence,
            endPricePence: $lastPrice->price_pence,
            periodDays: $days,
        );
    }

    /**
     * Detect price patterns for a product listing.
     */
    public function detectPricePattern(ProductListing $listing, int $lookbackDays = 90): ?PricePattern
    {
        $startDate = now()->subDays($lookbackDays);

        $salePrices = ProductListingPrice::query()
            ->where('product_listing_id', $listing->id)
            ->where('recorded_at', '>=', $startDate)
            ->whereNotNull('original_price_pence')
            ->whereColumn('price_pence', '<', 'original_price_pence')
            ->orderBy('recorded_at', 'asc')
            ->get();

        if ($salePrices->count() < 2) {
            return null;
        }

        $saleDates = $salePrices->map(fn ($p) => Carbon::parse($p->recorded_at))->values()->all();
        $discounts = $salePrices->map(function ($price) {
            return (int) round(
                (($price->original_price_pence - $price->price_pence) / $price->original_price_pence) * 100
            );
        });
        $avgDiscount = (int) round($discounts->avg());

        $intervals = [];
        for ($i = 1; $i < count($saleDates); $i++) {
            $intervals[] = $saleDates[$i - 1]->diffInDays($saleDates[$i]);
        }

        $avgInterval = count($intervals) > 0 ? (int) round(array_sum($intervals) / count($intervals)) : 0;

        $patternType = $this->classifyPattern($avgInterval, count($saleDates), $lookbackDays);
        $description = $this->generatePatternDescription($patternType, $avgInterval, $avgDiscount);

        return new PricePattern(
            patternType: $patternType,
            averageSaleDiscountPercentage: $avgDiscount,
            saleFrequencyDays: $avgInterval,
            saleDates: $saleDates,
            description: $description,
        );
    }

    /**
     * Get best time to buy recommendation for a product listing.
     */
    public function getBestTimeToBuy(ProductListing $listing): BestTimeToBuy
    {
        $statistics90d = $this->getPriceStatistics($listing, 90);
        $pattern = $this->detectPricePattern($listing, 90);

        $currentPrice = $listing->price_pence ?? 0;
        $averagePrice = $statistics90d?->averagePricePence ?? $currentPrice;
        $lowestPrice = $statistics90d?->minPricePence ?? $currentPrice;

        $isOnSaleNow = $listing->isOnSale();

        $recommendedDays = $this->determineRecommendedDaysOfWeek($listing);

        $expectedDaysUntilSale = null;
        if ($pattern !== null && $pattern->saleFrequencyDays > 0 && ! $isOnSaleNow) {
            $lastSaleDate = ! empty($pattern->saleDates) ? end($pattern->saleDates) : null;
            if ($lastSaleDate !== null) {
                $daysSinceLastSale = $lastSaleDate->diffInDays(now());
                $expectedDaysUntilSale = max(0, $pattern->saleFrequencyDays - $daysSinceLastSale);
            }
        }

        $recommendation = $this->generateBuyRecommendation(
            $isOnSaleNow,
            $currentPrice,
            $averagePrice,
            $lowestPrice,
            $expectedDaysUntilSale
        );

        return new BestTimeToBuy(
            isOnSaleNow: $isOnSaleNow,
            currentPricePence: $currentPrice,
            averagePricePence: $averagePrice,
            lowestHistoricalPricePence: $lowestPrice,
            expectedDaysUntilSale: $expectedDaysUntilSale,
            recommendedDaysOfWeek: $recommendedDays,
            recommendation: $recommendation,
        );
    }

    /**
     * Generate a weekly price report for a product category.
     */
    public function generateCategoryWeeklyReport(CanonicalCategory $category): CategoryPriceReport
    {
        $periodEnd = now();
        $periodStart = now()->subWeek();

        $listings = ProductListing::query()
            ->whereIn('id', function ($query) use ($category) {
                $query->select('product_listing_id')
                    ->from('product_listing_matches')
                    ->whereIn('product_id', function ($subQuery) use ($category) {
                        $subQuery->select('id')
                            ->from('products')
                            ->where('canonical_category', $category->value);
                    });
            })
            ->whereNotNull('price_pence')
            ->get();

        $totalListings = $listings->count();
        $averagePrice = $totalListings > 0 ? (int) round($listings->avg('price_pence')) : 0;
        $minPrice = $totalListings > 0 ? $listings->min('price_pence') : 0;
        $maxPrice = $totalListings > 0 ? $listings->max('price_pence') : 0;

        $listingsOnSale = $listings->filter(fn ($l) => $l->isOnSale())->count();
        $salePercentage = $totalListings > 0
            ? round(($listingsOnSale / $totalListings) * 100, 1)
            : 0.0;

        $priceChanges = $this->getPriceChangesForCategory($category, $periodStart, $periodEnd);
        $avgPriceChange = $priceChanges->isNotEmpty()
            ? round($priceChanges->avg('change_percentage'), 2)
            : 0.0;

        $topPriceDrops = $priceChanges
            ->filter(fn ($c) => $c['change_percentage'] < 0)
            ->sortBy('change_percentage')
            ->take(5)
            ->values()
            ->toArray();

        $topPriceIncreases = $priceChanges
            ->filter(fn ($c) => $c['change_percentage'] > 0)
            ->sortByDesc('change_percentage')
            ->take(5)
            ->values()
            ->toArray();

        return new CategoryPriceReport(
            category: $category,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            totalListings: $totalListings,
            averagePricePence: $averagePrice,
            minPricePence: $minPrice,
            maxPricePence: $maxPrice,
            averagePriceChange: $avgPriceChange,
            listingsOnSale: $listingsOnSale,
            salePercentage: $salePercentage,
            topPriceDrops: $topPriceDrops,
            topPriceIncreases: $topPriceIncreases,
        );
    }

    /**
     * Generate weekly price reports for all categories.
     *
     * @return array<string, CategoryPriceReport>
     */
    public function generateAllCategoriesWeeklyReport(): array
    {
        $reports = [];

        foreach (CanonicalCategory::cases() as $category) {
            $reports[$category->value] = $this->generateCategoryWeeklyReport($category);
        }

        return $reports;
    }

    /**
     * Get price trend indicator for a product (for display purposes).
     */
    public function getProductTrendIndicator(Product $product, int $days = 7): ?PriceTrend
    {
        $listing = $product->productListings()
            ->whereNotNull('price_pence')
            ->orderBy('price_pence', 'asc')
            ->first();

        if ($listing === null) {
            return null;
        }

        return $this->getPriceTrend($listing, $days);
    }

    /**
     * Calculate percentage price change.
     */
    private function calculatePriceChangePercentage(int $startPrice, int $endPrice): float
    {
        if ($startPrice === 0) {
            return 0.0;
        }

        return round((($endPrice - $startPrice) / $startPrice) * 100, 2);
    }

    /**
     * Calculate standard deviation for a collection of prices.
     *
     * @param  Collection<int, int>  $prices
     */
    private function calculateStandardDeviation(Collection $prices, int $mean): float
    {
        if ($prices->count() < 2) {
            return 0.0;
        }

        $sumSquaredDiffs = $prices->sum(function ($price) use ($mean) {
            return pow($price - $mean, 2);
        });

        $variance = $sumSquaredDiffs / ($prices->count() - 1);

        return round(sqrt($variance), 2);
    }

    /**
     * Determine the trend indicator based on change percentage and volatility.
     */
    private function determineTrendIndicator(float $changePercentage, ?PriceStatistics $statistics): PriceTrendIndicator
    {
        if ($statistics !== null && $statistics->averagePricePence > 0) {
            $volatilityPercent = ($statistics->standardDeviation / $statistics->averagePricePence) * 100;
            if ($volatilityPercent > self::VOLATILITY_THRESHOLD_PERCENT) {
                return PriceTrendIndicator::Volatile;
            }
        }

        if (abs($changePercentage) <= self::STABLE_THRESHOLD_PERCENT) {
            return PriceTrendIndicator::Stable;
        }

        return $changePercentage > 0 ? PriceTrendIndicator::Rising : PriceTrendIndicator::Falling;
    }

    /**
     * Classify the pattern type based on sale frequency.
     */
    private function classifyPattern(int $avgInterval, int $saleCount, int $lookbackDays): string
    {
        if ($saleCount === 0) {
            return 'none';
        }

        $salesPerMonth = ($saleCount / $lookbackDays) * 30;

        if ($salesPerMonth >= 4) {
            return 'frequent';
        }

        if ($avgInterval <= 14) {
            return 'bi_weekly';
        }

        if ($avgInterval <= 35) {
            return 'monthly';
        }

        return 'occasional';
    }

    /**
     * Generate a human-readable pattern description.
     */
    private function generatePatternDescription(string $patternType, int $avgInterval, int $avgDiscount): string
    {
        return match ($patternType) {
            'frequent' => "Frequently on sale with an average {$avgDiscount}% discount",
            'bi_weekly' => "Sales roughly every 2 weeks with an average {$avgDiscount}% discount",
            'monthly' => "Monthly sales pattern with an average {$avgDiscount}% discount",
            'occasional' => "Occasional sales approximately every {$avgInterval} days with an average {$avgDiscount}% discount",
            default => 'No clear sale pattern detected',
        };
    }

    /**
     * Determine recommended days of the week for purchases.
     *
     * @return list<string>
     */
    private function determineRecommendedDaysOfWeek(ProductListing $listing): array
    {
        $salePrices = ProductListingPrice::query()
            ->where('product_listing_id', $listing->id)
            ->where('recorded_at', '>=', now()->subDays(90))
            ->whereNotNull('original_price_pence')
            ->whereColumn('price_pence', '<', 'original_price_pence')
            ->get();

        if ($salePrices->count() < 3) {
            return [];
        }

        $dayOfWeekCounts = [];
        foreach ($salePrices as $price) {
            $dayOfWeek = Carbon::parse($price->recorded_at)->format('l');
            $dayOfWeekCounts[$dayOfWeek] = ($dayOfWeekCounts[$dayOfWeek] ?? 0) + 1;
        }

        arsort($dayOfWeekCounts);
        $topDays = array_slice(array_keys($dayOfWeekCounts), 0, 2);

        return $topDays;
    }

    /**
     * Generate a buy recommendation message.
     */
    private function generateBuyRecommendation(
        bool $isOnSaleNow,
        int $currentPrice,
        int $averagePrice,
        int $lowestPrice,
        ?int $expectedDaysUntilSale
    ): string {
        if ($isOnSaleNow) {
            $savingsPercent = $averagePrice > 0
                ? round((($averagePrice - $currentPrice) / $averagePrice) * 100, 0)
                : 0;

            if ($currentPrice <= $lowestPrice) {
                return "Excellent time to buy! This is the lowest price recorded. You're saving approximately {$savingsPercent}% compared to the average price.";
            }

            return "Good time to buy! The product is currently on sale, saving approximately {$savingsPercent}% compared to the average price.";
        }

        if ($currentPrice > $averagePrice) {
            $overPayPercent = round((($currentPrice - $averagePrice) / $averagePrice) * 100, 0);

            if ($expectedDaysUntilSale !== null && $expectedDaysUntilSale <= 7) {
                return "Consider waiting. The price is {$overPayPercent}% above average and a sale may occur within {$expectedDaysUntilSale} days.";
            }

            return "Current price is {$overPayPercent}% above average. Consider waiting for a sale if possible.";
        }

        if ($expectedDaysUntilSale !== null && $expectedDaysUntilSale <= 3) {
            return "A sale may be coming soon (estimated {$expectedDaysUntilSale} days). Consider waiting for better prices.";
        }

        return 'Current price is near or below average. Reasonable time to purchase.';
    }

    /**
     * Get price changes for listings in a category over a period.
     *
     * @return Collection<int, array{product_listing_id: int, title: string, current_price_pence: int, previous_price_pence: int, change_percentage: float}>
     */
    private function getPriceChangesForCategory(
        CanonicalCategory $category,
        Carbon $periodStart,
        Carbon $periodEnd
    ): Collection {
        $listingIds = ProductListing::query()
            ->whereIn('id', function ($query) use ($category) {
                $query->select('product_listing_id')
                    ->from('product_listing_matches')
                    ->whereIn('product_id', function ($subQuery) use ($category) {
                        $subQuery->select('id')
                            ->from('products')
                            ->where('canonical_category', $category->value);
                    });
            })
            ->pluck('id');

        $changes = collect();

        foreach ($listingIds as $listingId) {
            $listing = ProductListing::query()->find($listingId);

            if ($listing === null || $listing->price_pence === null) {
                continue;
            }

            $previousPrice = ProductListingPrice::query()
                ->where('product_listing_id', $listingId)
                ->where('recorded_at', '<', $periodStart)
                ->orderBy('recorded_at', 'desc')
                ->value('price_pence');

            if ($previousPrice === null || $previousPrice === 0) {
                continue;
            }

            $changePercentage = round(
                (($listing->price_pence - $previousPrice) / $previousPrice) * 100,
                2
            );

            $changes->push([
                'product_listing_id' => $listingId,
                'title' => $listing->title ?? 'Unknown',
                'current_price_pence' => $listing->price_pence,
                'previous_price_pence' => $previousPrice,
                'change_percentage' => $changePercentage,
            ]);
        }

        return $changes;
    }
}
