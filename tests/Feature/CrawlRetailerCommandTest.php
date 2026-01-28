<?php

use App\Crawler\Scrapers\BMCrawler;
use App\Crawler\Scrapers\TescoCrawler;
use App\Jobs\Crawler\CrawlProductListingsJob;
use App\Models\Retailer;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([CrawlProductListingsJob::class]);
});

test('command dispatches crawl jobs for a single retailer', function () {
    Retailer::factory()
        ->withCrawler(BMCrawler::class)
        ->create(['name' => 'B&M', 'slug' => 'bm']);

    $this->artisan('crawler:run bm')
        ->expectsOutputToContain('Starting B&M product listing crawler')
        ->expectsOutputToContain('Dispatched')
        ->assertExitCode(0);

    Bus::assertDispatched(CrawlProductListingsJob::class);
});

test('command dispatches crawl jobs for multiple retailers', function () {
    Retailer::factory()
        ->withCrawler(BMCrawler::class)
        ->create(['name' => 'B&M', 'slug' => 'bm']);

    Retailer::factory()
        ->withCrawler(TescoCrawler::class)
        ->create(['name' => 'Tesco', 'slug' => 'tesco']);

    $this->artisan('crawler:run bm tesco')
        ->expectsOutputToContain('Starting B&M product listing crawler')
        ->expectsOutputToContain('Starting Tesco product listing crawler')
        ->expectsOutputToContain('2 retailer(s)')
        ->assertExitCode(0);

    // Each crawler has multiple starting URLs - just verify jobs were dispatched
    Bus::assertDispatched(CrawlProductListingsJob::class);
});

test('command with --all dispatches jobs for all active retailers', function () {
    Retailer::factory()
        ->withCrawler(BMCrawler::class)
        ->create(['name' => 'B&M', 'slug' => 'bm']);

    Retailer::factory()
        ->withCrawler(TescoCrawler::class)
        ->create(['name' => 'Tesco', 'slug' => 'tesco']);

    $this->artisan('crawler:run --all')
        ->expectsOutputToContain('Starting B&M product listing crawler')
        ->expectsOutputToContain('Starting Tesco product listing crawler')
        ->expectsOutputToContain('2 retailer(s)')
        ->assertExitCode(0);

    Bus::assertDispatched(CrawlProductListingsJob::class);
});

test('command skips disabled retailers with --all', function () {
    Retailer::factory()
        ->withCrawler(BMCrawler::class)
        ->create(['name' => 'B&M', 'slug' => 'bm']);

    Retailer::factory()
        ->disabled()
        ->withCrawler(TescoCrawler::class)
        ->create(['name' => 'Disabled Store', 'slug' => 'disabled']);

    $this->artisan('crawler:run --all')
        ->expectsOutputToContain('Starting B&M product listing crawler')
        ->expectsOutputToContain('1 retailer(s)')
        ->assertExitCode(0);

    // Should dispatch for B&M (4 URLs), not the disabled store
    Bus::assertDispatched(CrawlProductListingsJob::class);
});

test('command skips paused retailers with --all', function () {
    Retailer::factory()
        ->withCrawler(BMCrawler::class)
        ->create(['name' => 'B&M', 'slug' => 'bm']);

    Retailer::factory()
        ->paused()
        ->withCrawler(TescoCrawler::class)
        ->create(['name' => 'Paused Store', 'slug' => 'paused']);

    $this->artisan('crawler:run --all')
        ->expectsOutputToContain('Starting B&M product listing crawler')
        ->expectsOutputToContain('1 retailer(s)')
        ->assertExitCode(0);

    // Should dispatch for B&M (4 URLs), not the paused store
    Bus::assertDispatched(CrawlProductListingsJob::class);
});

test('command warns when trying to crawl disabled retailer by slug', function () {
    Retailer::factory()
        ->disabled()
        ->withCrawler(BMCrawler::class)
        ->create(['name' => 'Disabled Store', 'slug' => 'disabled']);

    $this->artisan('crawler:run disabled')
        ->expectsOutputToContain('Skipping Disabled Store: Retailer is Disabled')
        ->assertExitCode(0);

    Bus::assertNotDispatched(CrawlProductListingsJob::class);
});

test('command warns when trying to crawl paused retailer by slug', function () {
    Retailer::factory()
        ->paused()
        ->withCrawler(BMCrawler::class)
        ->create(['name' => 'Paused Store', 'slug' => 'paused']);

    $this->artisan('crawler:run paused')
        ->expectsOutputToContain('Skipping Paused Store: Retailer is paused until')
        ->assertExitCode(0);

    Bus::assertNotDispatched(CrawlProductListingsJob::class);
});

test('command fails with help when no retailer specified', function () {
    $this->artisan('crawler:run')
        ->expectsOutputToContain('No retailers found to crawl')
        ->expectsOutputToContain('Usage:')
        ->assertExitCode(1);

    Bus::assertNotDispatched(CrawlProductListingsJob::class);
});

test('command fails when unknown retailer slug provided', function () {
    $this->artisan('crawler:run unknown-retailer')
        ->expectsOutputToContain('No retailers found to crawl')
        ->assertExitCode(1);

    Bus::assertNotDispatched(CrawlProductListingsJob::class);
});

test('command skips retailers without crawler class', function () {
    Retailer::factory()->create([
        'name' => 'No Crawler',
        'slug' => 'no-crawler',
        'crawler_class' => null,
    ]);

    $this->artisan('crawler:run no-crawler')
        ->expectsOutputToContain('Skipping No Crawler: Invalid or missing crawler_class')
        ->assertExitCode(0);

    Bus::assertNotDispatched(CrawlProductListingsJob::class);
});

test('command updates last_crawled_at timestamp', function () {
    $retailer = Retailer::factory()
        ->withCrawler(BMCrawler::class)
        ->create(['last_crawled_at' => null]);

    $this->artisan('crawler:run', ['retailer' => [$retailer->slug]])
        ->assertExitCode(0);

    $retailer->refresh();
    expect($retailer->last_crawled_at)->not->toBeNull();
});

test('command dispatches jobs to specified queue', function () {
    Retailer::factory()
        ->withCrawler(BMCrawler::class)
        ->create(['name' => 'B&M', 'slug' => 'bm']);

    $this->artisan('crawler:run bm --queue=high-priority')
        ->assertExitCode(0);

    Bus::assertDispatched(CrawlProductListingsJob::class, function ($job) {
        return $job->queue === 'high-priority';
    });
});

test('command lists available retailers when no match found', function () {
    Retailer::factory()
        ->withCrawler(BMCrawler::class)
        ->create(['name' => 'B&M', 'slug' => 'bm']);

    $this->artisan('crawler:run unknown')
        ->expectsOutputToContain('Available retailers:')
        ->expectsOutputToContain('bm (B&M)')
        ->assertExitCode(1);
});
