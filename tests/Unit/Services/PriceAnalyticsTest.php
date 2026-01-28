<?php

declare(strict_types=1);

use App\DTOs\BestTimeToBuy;
use App\DTOs\CategoryPriceReport;
use App\DTOs\PricePattern;
use App\DTOs\PriceStatistics;
use App\DTOs\PriceTrend;
use App\Enums\CanonicalCategory;
use App\Enums\PriceTrendIndicator;
use App\Models\Product;
use App\Models\ProductListing;
use App\Models\ProductListingMatch;
use App\Models\ProductListingPrice;
use App\Models\Retailer;
use App\Services\PriceAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupPriceAnalytics(): array
{
    $priceAnalytics = new PriceAnalytics;
    $retailer = Retailer::factory()->create();

    return compact('priceAnalytics', 'retailer');
}

function createListingWithPriceHistory(Retailer $retailer, array $priceData): ProductListing
{
    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'price_pence' => end($priceData)['price'],
        'original_price_pence' => end($priceData)['original'] ?? null,
    ]);

    foreach ($priceData as $data) {
        ProductListingPrice::factory()->create([
            'product_listing_id' => $listing->id,
            'price_pence' => $data['price'],
            'original_price_pence' => $data['original'] ?? null,
            'recorded_at' => $data['date'],
        ]);
    }

    return $listing;
}

// Average Price Tests
test('getAveragePrice calculates correct average for listing', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = createListingWithPriceHistory($retailer, [
        ['price' => 1000, 'date' => now()->subDays(5)],
        ['price' => 1200, 'date' => now()->subDays(3)],
        ['price' => 1100, 'date' => now()->subDays(1)],
    ]);

    $average = $priceAnalytics->getAveragePrice($listing, 7);

    expect($average)->toBe(1100);
});

test('getAveragePrice returns null when no price data in period', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
    ]);

    $average = $priceAnalytics->getAveragePrice($listing, 7);

    expect($average)->toBeNull();
});

test('getAveragePrice only considers prices within the period', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = createListingWithPriceHistory($retailer, [
        ['price' => 2000, 'date' => now()->subDays(30)],
        ['price' => 1000, 'date' => now()->subDays(5)],
        ['price' => 1200, 'date' => now()->subDays(3)],
    ]);

    $average = $priceAnalytics->getAveragePrice($listing, 7);

    expect($average)->toBe(1100);
});

// Price Statistics Tests
test('getPriceStatistics returns correct statistics', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = createListingWithPriceHistory($retailer, [
        ['price' => 800, 'date' => now()->subDays(6)],
        ['price' => 1000, 'date' => now()->subDays(4)],
        ['price' => 1200, 'date' => now()->subDays(2)],
        ['price' => 1000, 'date' => now()->subDays(1)],
    ]);

    $stats = $priceAnalytics->getPriceStatistics($listing, 7);

    expect($stats)->toBeInstanceOf(PriceStatistics::class)
        ->and($stats->averagePricePence)->toBe(1000)
        ->and($stats->minPricePence)->toBe(800)
        ->and($stats->maxPricePence)->toBe(1200)
        ->and($stats->priceRangePence)->toBe(400)
        ->and($stats->dataPointCount)->toBe(4)
        ->and($stats->periodDays)->toBe(7);
});

test('getPriceStatistics returns null when no data', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
    ]);

    $stats = $priceAnalytics->getPriceStatistics($listing, 7);

    expect($stats)->toBeNull();
});

test('getMultiPeriodStatistics returns statistics for all periods', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = createListingWithPriceHistory($retailer, [
        ['price' => 900, 'date' => now()->subDays(60)],
        ['price' => 1000, 'date' => now()->subDays(20)],
        ['price' => 1100, 'date' => now()->subDays(5)],
    ]);

    $multiStats = $priceAnalytics->getMultiPeriodStatistics($listing);

    expect($multiStats)->toHaveKeys(['7d', '30d', '90d'])
        ->and($multiStats['7d'])->toBeInstanceOf(PriceStatistics::class)
        ->and($multiStats['30d'])->toBeInstanceOf(PriceStatistics::class)
        ->and($multiStats['90d'])->toBeInstanceOf(PriceStatistics::class);
});

// Price Trend Tests
test('getPriceTrend returns rising trend when prices increase', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = createListingWithPriceHistory($retailer, [
        ['price' => 1000, 'date' => now()->subDays(6)],
        ['price' => 1100, 'date' => now()->subDays(4)],
        ['price' => 1200, 'date' => now()->subDays(2)],
        ['price' => 1300, 'date' => now()->subDays(1)],
    ]);

    $trend = $priceAnalytics->getPriceTrend($listing, 7);

    expect($trend)->toBeInstanceOf(PriceTrend::class)
        ->and($trend->indicator)->toBe(PriceTrendIndicator::Rising)
        ->and($trend->startPricePence)->toBe(1000)
        ->and($trend->endPricePence)->toBe(1300)
        ->and($trend->changePercentage)->toBe(30.0);
});

test('getPriceTrend returns falling trend when prices decrease', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = createListingWithPriceHistory($retailer, [
        ['price' => 1300, 'date' => now()->subDays(6)],
        ['price' => 1200, 'date' => now()->subDays(4)],
        ['price' => 1100, 'date' => now()->subDays(2)],
        ['price' => 1000, 'date' => now()->subDays(1)],
    ]);

    $trend = $priceAnalytics->getPriceTrend($listing, 7);

    expect($trend->indicator)->toBe(PriceTrendIndicator::Falling)
        ->and($trend->changePercentage)->toBeLessThan(0)
        ->and($trend->changePercentage)->toBeGreaterThan(-25);
});

test('getPriceTrend returns stable trend for small price changes', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = createListingWithPriceHistory($retailer, [
        ['price' => 1000, 'date' => now()->subDays(6)],
        ['price' => 1005, 'date' => now()->subDays(4)],
        ['price' => 1010, 'date' => now()->subDays(2)],
        ['price' => 1015, 'date' => now()->subDays(1)],
    ]);

    $trend = $priceAnalytics->getPriceTrend($listing, 7);

    expect($trend->indicator)->toBe(PriceTrendIndicator::Stable);
});

test('getPriceTrend returns volatile trend for high volatility', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = createListingWithPriceHistory($retailer, [
        ['price' => 800, 'date' => now()->subDays(6)],
        ['price' => 1400, 'date' => now()->subDays(4)],
        ['price' => 600, 'date' => now()->subDays(2)],
        ['price' => 1200, 'date' => now()->subDays(1)],
    ]);

    $trend = $priceAnalytics->getPriceTrend($listing, 7);

    expect($trend->indicator)->toBe(PriceTrendIndicator::Volatile);
});

test('getPriceTrend returns null when no data', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
    ]);

    $trend = $priceAnalytics->getPriceTrend($listing, 7);

    expect($trend)->toBeNull();
});

// Price Pattern Tests
test('detectPricePattern detects frequent sale pattern', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $priceData = [];
    for ($i = 0; $i < 12; $i++) {
        $daysAgo = 90 - ($i * 7);
        $isOnSale = $i % 2 === 0;
        $priceData[] = [
            'price' => $isOnSale ? 800 : 1000,
            'original' => $isOnSale ? 1000 : null,
            'date' => now()->subDays($daysAgo),
        ];
    }

    $listing = createListingWithPriceHistory($retailer, $priceData);

    $pattern = $priceAnalytics->detectPricePattern($listing, 90);

    expect($pattern)->toBeInstanceOf(PricePattern::class)
        ->and($pattern->patternType)->toBeIn(['frequent', 'bi_weekly'])
        ->and($pattern->averageSaleDiscountPercentage)->toBe(20);
});

test('detectPricePattern returns null for insufficient data', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = createListingWithPriceHistory($retailer, [
        ['price' => 800, 'original' => 1000, 'date' => now()->subDays(30)],
    ]);

    $pattern = $priceAnalytics->detectPricePattern($listing, 90);

    expect($pattern)->toBeNull();
});

test('detectPricePattern calculates correct discount percentage', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = createListingWithPriceHistory($retailer, [
        ['price' => 700, 'original' => 1000, 'date' => now()->subDays(60)],
        ['price' => 600, 'original' => 1000, 'date' => now()->subDays(30)],
        ['price' => 500, 'original' => 1000, 'date' => now()->subDays(1)],
    ]);

    $pattern = $priceAnalytics->detectPricePattern($listing, 90);

    expect($pattern->averageSaleDiscountPercentage)->toBe(40);
});

// Best Time To Buy Tests
test('getBestTimeToBuy returns correct recommendation when on sale', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'price_pence' => 800,
        'original_price_pence' => 1000,
    ]);

    ProductListingPrice::factory()->create([
        'product_listing_id' => $listing->id,
        'price_pence' => 1000,
        'original_price_pence' => null,
        'recorded_at' => now()->subDays(30),
    ]);

    ProductListingPrice::factory()->create([
        'product_listing_id' => $listing->id,
        'price_pence' => 800,
        'original_price_pence' => 1000,
        'recorded_at' => now(),
    ]);

    $recommendation = $priceAnalytics->getBestTimeToBuy($listing);

    expect($recommendation)->toBeInstanceOf(BestTimeToBuy::class)
        ->and($recommendation->isOnSaleNow)->toBeTrue()
        ->and($recommendation->currentPricePence)->toBe(800);
});

test('getBestTimeToBuy returns correct recommendation when not on sale', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'price_pence' => 1200,
        'original_price_pence' => null,
    ]);

    ProductListingPrice::factory()->create([
        'product_listing_id' => $listing->id,
        'price_pence' => 1000,
        'original_price_pence' => null,
        'recorded_at' => now()->subDays(30),
    ]);

    $recommendation = $priceAnalytics->getBestTimeToBuy($listing);

    expect($recommendation->isOnSaleNow)->toBeFalse()
        ->and($recommendation->currentPricePence)->toBe(1200)
        ->and($recommendation->recommendation)->toContain('above average');
});

test('getBestTimeToBuy calculates current savings percentage', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'price_pence' => 800,
        'original_price_pence' => 1000,
    ]);

    ProductListingPrice::factory()->create([
        'product_listing_id' => $listing->id,
        'price_pence' => 1000,
        'original_price_pence' => null,
        'recorded_at' => now()->subDays(30),
    ]);

    $recommendation = $priceAnalytics->getBestTimeToBuy($listing);

    expect($recommendation->getCurrentSavingsPercentage())->toBe(20.0);
});

// Category Weekly Report Tests
test('generateCategoryWeeklyReport generates correct report', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $product = Product::factory()->create([
        'canonical_category' => CanonicalCategory::DryFood,
    ]);

    $listing1 = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'price_pence' => 1000,
        'original_price_pence' => 1200,
    ]);

    $listing2 = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'price_pence' => 1500,
        'original_price_pence' => null,
    ]);

    ProductListingMatch::factory()->create([
        'product_id' => $product->id,
        'product_listing_id' => $listing1->id,
    ]);

    ProductListingMatch::factory()->create([
        'product_id' => $product->id,
        'product_listing_id' => $listing2->id,
    ]);

    $report = $priceAnalytics->generateCategoryWeeklyReport(CanonicalCategory::DryFood);

    expect($report)->toBeInstanceOf(CategoryPriceReport::class)
        ->and($report->category)->toBe(CanonicalCategory::DryFood)
        ->and($report->totalListings)->toBe(2)
        ->and($report->averagePricePence)->toBe(1250)
        ->and($report->minPricePence)->toBe(1000)
        ->and($report->maxPricePence)->toBe(1500)
        ->and($report->listingsOnSale)->toBe(1);
});

test('generateCategoryWeeklyReport handles empty categories', function () {
    ['priceAnalytics' => $priceAnalytics] = setupPriceAnalytics();

    $report = $priceAnalytics->generateCategoryWeeklyReport(CanonicalCategory::Dental);

    expect($report->totalListings)->toBe(0)
        ->and($report->averagePricePence)->toBe(0)
        ->and($report->salePercentage)->toBe(0.0);
});

test('generateAllCategoriesWeeklyReport generates reports for all categories', function () {
    ['priceAnalytics' => $priceAnalytics] = setupPriceAnalytics();

    $reports = $priceAnalytics->generateAllCategoriesWeeklyReport();

    expect($reports)->toHaveCount(count(CanonicalCategory::cases()));

    foreach (CanonicalCategory::cases() as $category) {
        expect($reports)->toHaveKey($category->value)
            ->and($reports[$category->value])->toBeInstanceOf(CategoryPriceReport::class);
    }
});

// Product Trend Indicator Tests
test('getProductTrendIndicator returns trend for product with listings', function () {
    ['priceAnalytics' => $priceAnalytics, 'retailer' => $retailer] = setupPriceAnalytics();

    $product = Product::factory()->create();

    $listing = createListingWithPriceHistory($retailer, [
        ['price' => 1000, 'date' => now()->subDays(5)],
        ['price' => 1200, 'date' => now()->subDays(1)],
    ]);

    ProductListingMatch::factory()->create([
        'product_id' => $product->id,
        'product_listing_id' => $listing->id,
    ]);

    $product->refresh();

    $trend = $priceAnalytics->getProductTrendIndicator($product, 7);

    expect($trend)->toBeInstanceOf(PriceTrend::class);
});

test('getProductTrendIndicator returns null for product without listings', function () {
    ['priceAnalytics' => $priceAnalytics] = setupPriceAnalytics();

    $product = Product::factory()->create();

    $trend = $priceAnalytics->getProductTrendIndicator($product, 7);

    expect($trend)->toBeNull();
});

// DTO toArray Tests
test('PriceTrend toArray returns correct structure', function () {
    $trend = new PriceTrend(
        indicator: PriceTrendIndicator::Rising,
        changePercentage: 10.5,
        startPricePence: 1000,
        endPricePence: 1105,
        periodDays: 7
    );

    $array = $trend->toArray();

    expect($array)->toHaveKeys([
        'indicator',
        'indicator_label',
        'indicator_icon',
        'indicator_color',
        'change_percentage',
        'start_price_pence',
        'end_price_pence',
        'period_days',
    ])
        ->and($array['indicator'])->toBe('rising')
        ->and($array['indicator_label'])->toBe('Rising')
        ->and($array['indicator_icon'])->toBe('↑')
        ->and($array['indicator_color'])->toBe('red');
});

test('PriceStatistics toArray returns correct structure', function () {
    $stats = new PriceStatistics(
        averagePricePence: 1000,
        minPricePence: 800,
        maxPricePence: 1200,
        priceRangePence: 400,
        standardDeviation: 150.5,
        dataPointCount: 10,
        periodDays: 7
    );

    $array = $stats->toArray();

    expect($array)->toHaveKeys([
        'average_price_pence',
        'min_price_pence',
        'max_price_pence',
        'price_range_pence',
        'standard_deviation',
        'data_point_count',
        'period_days',
    ]);
});

test('BestTimeToBuy toArray returns correct structure', function () {
    $recommendation = new BestTimeToBuy(
        isOnSaleNow: true,
        currentPricePence: 800,
        averagePricePence: 1000,
        lowestHistoricalPricePence: 750,
        expectedDaysUntilSale: null,
        recommendedDaysOfWeek: ['Monday', 'Friday'],
        recommendation: 'Good time to buy!'
    );

    $array = $recommendation->toArray();

    expect($array)->toHaveKeys([
        'is_on_sale_now',
        'current_price_pence',
        'average_price_pence',
        'lowest_historical_price_pence',
        'current_savings_percentage',
        'expected_days_until_sale',
        'recommended_days_of_week',
        'recommendation',
    ])
        ->and($array['is_on_sale_now'])->toBeTrue()
        ->and($array['current_savings_percentage'])->toBe(20.0);
});

// Enum Tests
test('PriceTrendIndicator enum has correct values and methods', function () {
    expect(PriceTrendIndicator::Rising->value)->toBe('rising')
        ->and(PriceTrendIndicator::Rising->label())->toBe('Rising')
        ->and(PriceTrendIndicator::Rising->icon())->toBe('↑')
        ->and(PriceTrendIndicator::Rising->color())->toBe('red');

    expect(PriceTrendIndicator::Falling->value)->toBe('falling')
        ->and(PriceTrendIndicator::Falling->label())->toBe('Falling')
        ->and(PriceTrendIndicator::Falling->icon())->toBe('↓')
        ->and(PriceTrendIndicator::Falling->color())->toBe('green');

    expect(PriceTrendIndicator::Stable->value)->toBe('stable')
        ->and(PriceTrendIndicator::Stable->label())->toBe('Stable')
        ->and(PriceTrendIndicator::Stable->icon())->toBe('→')
        ->and(PriceTrendIndicator::Stable->color())->toBe('gray');

    expect(PriceTrendIndicator::Volatile->value)->toBe('volatile')
        ->and(PriceTrendIndicator::Volatile->label())->toBe('Volatile')
        ->and(PriceTrendIndicator::Volatile->icon())->toBe('↕')
        ->and(PriceTrendIndicator::Volatile->color())->toBe('yellow');
});
