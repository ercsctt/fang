<?php

declare(strict_types=1);

use App\Models\ProductListing;
use App\Models\ProductListingPrice;
use App\Models\Retailer;

test('command displays no data message when no retailers exist', function () {
    $this->artisan('crawl:quality-report')
        ->expectsOutputToContain('No retailers or listings found')
        ->assertExitCode(0);
});

test('command displays no data message when retailers have no listings', function () {
    Retailer::factory()->create(['name' => 'Empty Store']);

    $this->artisan('crawl:quality-report')
        ->expectsOutputToContain('No retailers or listings found')
        ->assertExitCode(0);
});

test('command displays quality report for retailer with listings', function () {
    $retailer = Retailer::factory()->create(['name' => 'Test Store', 'slug' => 'test-store']);

    ProductListing::factory()
        ->for($retailer)
        ->count(5)
        ->recentlyScraped()
        ->create([
            'price_pence' => 1000,
            'description' => 'Test description',
            'images' => ['image.jpg'],
            'brand' => 'Test Brand',
            'ingredients' => 'Chicken, Rice',
        ]);

    $this->artisan('crawl:quality-report')
        ->expectsOutputToContain('Data Quality Report')
        ->expectsOutputToContain('Summary by Retailer')
        ->expectsOutputToContain('Test Store')
        ->assertExitCode(0);
});

test('command counts missing price correctly', function () {
    $retailer = Retailer::factory()->create(['name' => 'Price Test Store', 'slug' => 'price-test']);

    // 3 with price, 2 without
    ProductListing::factory()->for($retailer)->count(3)->create(['price_pence' => 1000]);
    ProductListing::factory()->for($retailer)->count(2)->create(['price_pence' => null]);

    $this->artisan('crawl:quality-report --retailer=price-test')
        ->expectsOutputToContain('Price')
        ->expectsOutputToContain('2')
        ->assertExitCode(0);
});

test('command counts missing description correctly', function () {
    $retailer = Retailer::factory()->create(['name' => 'Description Test Store', 'slug' => 'desc-test']);

    ProductListing::factory()->for($retailer)->count(3)->create(['description' => 'Has description']);
    ProductListing::factory()->for($retailer)->count(2)->create(['description' => null]);
    ProductListing::factory()->for($retailer)->count(1)->create(['description' => '']);

    $this->artisan('crawl:quality-report --retailer=desc-test')
        ->expectsOutputToContain('Description')
        ->expectsOutputToContain('3')
        ->assertExitCode(0);
});

test('command counts missing images correctly', function () {
    $retailer = Retailer::factory()->create(['name' => 'Images Test Store', 'slug' => 'images-test']);

    ProductListing::factory()->for($retailer)->count(2)->create(['images' => ['img.jpg']]);
    ProductListing::factory()->for($retailer)->count(1)->create(['images' => null]);
    ProductListing::factory()->for($retailer)->count(1)->create(['images' => []]);

    $this->artisan('crawl:quality-report --retailer=images-test')
        ->expectsOutputToContain('Images')
        ->expectsOutputToContain('2')
        ->assertExitCode(0);
});

test('command counts missing brand correctly', function () {
    $retailer = Retailer::factory()->create(['name' => 'Brand Test Store', 'slug' => 'brand-test']);

    ProductListing::factory()->for($retailer)->count(4)->create(['brand' => 'Test Brand']);
    ProductListing::factory()->for($retailer)->count(3)->create(['brand' => null]);

    $this->artisan('crawl:quality-report --retailer=brand-test')
        ->expectsOutputToContain('Brand')
        ->expectsOutputToContain('3')
        ->assertExitCode(0);
});

test('command counts missing ingredients correctly', function () {
    $retailer = Retailer::factory()->create(['name' => 'Ingredients Test Store', 'slug' => 'ingredients-test']);

    ProductListing::factory()->for($retailer)->count(2)->create(['ingredients' => 'Chicken, Rice']);
    ProductListing::factory()->for($retailer)->count(5)->create(['ingredients' => null]);

    $this->artisan('crawl:quality-report --retailer=ingredients-test')
        ->expectsOutputToContain('Ingredients Test Store')
        ->expectsOutputToContain('Ingredients')
        ->assertExitCode(0);

    // Verify the calculation is correct
    $missingCount = ProductListing::query()
        ->where('retailer_id', $retailer->id)
        ->where(function ($query) {
            $query->whereNull('ingredients')
                ->orWhere('ingredients', '');
        })
        ->count();

    expect($missingCount)->toBe(5);
});

test('command counts stale listings correctly', function () {
    $retailer = Retailer::factory()->create(['name' => 'Stale Test Store', 'slug' => 'stale-test']);

    // 2 recently scraped
    ProductListing::factory()->for($retailer)->count(2)->recentlyScraped()->create();

    // 3 stale (>48 hours)
    ProductListing::factory()->for($retailer)->count(3)->create([
        'last_scraped_at' => now()->subHours(50),
    ]);

    // 1 never scraped
    ProductListing::factory()->for($retailer)->count(1)->create([
        'last_scraped_at' => null,
    ]);

    $this->artisan('crawl:quality-report --retailer=stale-test')
        ->expectsOutputToContain('Stale Test Store')
        ->assertExitCode(0);

    // Verify the calculation is correct
    $staleCount = ProductListing::query()
        ->where('retailer_id', $retailer->id)
        ->where(function ($query) {
            $query->whereNull('last_scraped_at')
                ->orWhere('last_scraped_at', '<', now()->subHours(48));
        })
        ->count();

    expect($staleCount)->toBe(4);
});

test('command calculates completeness score correctly', function () {
    $retailer = Retailer::factory()->create(['name' => 'Complete Store', 'slug' => 'complete']);

    // Create 1 listing with all fields populated
    ProductListing::factory()->for($retailer)->create([
        'title' => 'Test Product',
        'description' => 'Test description',
        'price_pence' => 1000,
        'brand' => 'Test Brand',
        'images' => ['image.jpg'],
        'ingredients' => 'Chicken, Rice',
    ]);

    $this->artisan('crawl:quality-report --retailer=complete')
        ->expectsOutputToContain('100%')
        ->assertExitCode(0);
});

test('command calculates partial completeness score correctly', function () {
    $retailer = Retailer::factory()->create(['name' => 'Partial Store', 'slug' => 'partial']);

    // Create 1 listing with half the fields populated (3 of 6)
    ProductListing::factory()->for($retailer)->create([
        'title' => 'Test Product',
        'description' => 'Test description',
        'price_pence' => 1000,
        'brand' => null,
        'images' => null,
        'ingredients' => null,
    ]);

    $this->artisan('crawl:quality-report --retailer=partial')
        ->expectsOutputToContain('50%')
        ->assertExitCode(0);
});

test('command filters by retailer slug', function () {
    $retailer1 = Retailer::factory()->create(['name' => 'Store One', 'slug' => 'store-one']);
    $retailer2 = Retailer::factory()->create(['name' => 'Store Two', 'slug' => 'store-two']);

    ProductListing::factory()->for($retailer1)->count(3)->create();
    ProductListing::factory()->for($retailer2)->count(5)->create();

    $this->artisan('crawl:quality-report --retailer=store-one')
        ->expectsOutputToContain('Store One')
        ->doesntExpectOutput('Store Two')
        ->assertExitCode(0);
});

test('command detects price anomalies greater than 50 percent', function () {
    $retailer = Retailer::factory()->create(['name' => 'Anomaly Store', 'slug' => 'anomaly']);

    $listing = ProductListing::factory()->for($retailer)->create([
        'title' => 'Price Drop Product',
        'price_pence' => 500,
    ]);

    // Create price history with >50% change
    ProductListingPrice::factory()->for($listing)->create([
        'price_pence' => 1000,
        'recorded_at' => now()->subDays(2),
    ]);

    ProductListingPrice::factory()->for($listing)->create([
        'price_pence' => 400,
        'recorded_at' => now()->subDay(),
    ]);

    $this->artisan('crawl:quality-report --retailer=anomaly')
        ->expectsOutputToContain('Price Anomalies')
        ->assertExitCode(0);
});

test('command handles multiple retailers', function () {
    $retailer1 = Retailer::factory()->create(['name' => 'Multi Store One', 'slug' => 'multi-one']);
    $retailer2 = Retailer::factory()->create(['name' => 'Multi Store Two', 'slug' => 'multi-two']);

    ProductListing::factory()->for($retailer1)->count(5)->create();
    ProductListing::factory()->for($retailer2)->count(10)->create();

    // Test without filter to show all retailers
    $this->artisan('crawl:quality-report')
        ->expectsOutputToContain('Multi Store One')
        ->expectsOutputToContain('Multi Store Two')
        ->assertExitCode(0);

    // Verify both retailers have the correct counts
    expect($retailer1->productListings()->count())->toBe(5);
    expect($retailer2->productListings()->count())->toBe(10);
});

test('command shows total listings count per retailer', function () {
    $retailer = Retailer::factory()->create(['name' => 'Count Store', 'slug' => 'count-store']);

    ProductListing::factory()->for($retailer)->count(7)->create();

    $this->artisan('crawl:quality-report --retailer=count-store')
        ->expectsOutputToContain('7')
        ->assertExitCode(0);
});

test('command exports to csv file', function () {
    $retailer = Retailer::factory()->create(['name' => 'Export Store', 'slug' => 'export-store']);
    ProductListing::factory()->for($retailer)->count(3)->create();

    $this->artisan('crawl:quality-report --export=csv')
        ->expectsOutputToContain('CSV exported to')
        ->assertExitCode(0);

    // Find and cleanup the CSV file
    $files = glob(storage_path('app/data-quality-report-*.csv'));
    expect($files)->not->toBeEmpty();

    // Cleanup
    foreach ($files as $file) {
        unlink($file);
    }
});

test('csv export contains correct headers', function () {
    $retailer = Retailer::factory()->create(['name' => 'Header Test', 'slug' => 'header-test']);
    ProductListing::factory()->for($retailer)->create();

    // Run the command
    $this->artisan('crawl:quality-report --export=csv')
        ->assertExitCode(0);

    // Find the CSV file
    $files = glob(storage_path('app/data-quality-report-*.csv'));
    expect($files)->not->toBeEmpty();

    $content = file_get_contents($files[0]);

    expect($content)->toContain('Retailer');
    expect($content)->toContain('Slug');
    expect($content)->toContain('Total Listings');
    expect($content)->toContain('Missing Price');
    expect($content)->toContain('Missing Description');
    expect($content)->toContain('Missing Images');
    expect($content)->toContain('Missing Brand');
    expect($content)->toContain('Missing Ingredients');
    expect($content)->toContain('Stale Listings');
    expect($content)->toContain('Price Anomalies Count');
    expect($content)->toContain('Completeness Score %');

    // Cleanup
    unlink($files[0]);
});

test('csv export contains correct data', function () {
    $retailer = Retailer::factory()->create(['name' => 'CSV Data Test', 'slug' => 'csv-data-test']);

    ProductListing::factory()->for($retailer)->count(5)->recentlyScraped()->create([
        'price_pence' => 1000,
        'description' => 'Test',
        'images' => ['img.jpg'],
        'brand' => 'Brand',
        'ingredients' => 'Chicken',
    ]);

    $this->artisan('crawl:quality-report --export=csv --retailer=csv-data-test')
        ->assertExitCode(0);

    $files = glob(storage_path('app/data-quality-report-*.csv'));
    expect($files)->not->toBeEmpty();

    $content = file_get_contents($files[0]);

    expect($content)->toContain('CSV Data Test');
    expect($content)->toContain('csv-data-test');
    expect($content)->toContain(',5,');

    // Cleanup
    unlink($files[0]);
});
