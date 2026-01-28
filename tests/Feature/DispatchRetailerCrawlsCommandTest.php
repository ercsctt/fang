<?php

use App\Crawler\Scrapers\BMCrawler;
use App\Enums\RetailerStatus;
use App\Jobs\Crawler\CrawlProductListingsJob;
use App\Models\Retailer;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([CrawlProductListingsJob::class]);
});

test('command dispatches crawl jobs for active retailers', function () {
    $retailer = Retailer::factory()
        ->withCrawler(BMCrawler::class)
        ->create(['name' => 'B&M', 'slug' => 'bm']);

    $this->artisan('crawler:dispatch-all')
        ->expectsOutputToContain('Dispatching crawl jobs for 1 retailer(s)')
        ->expectsOutputToContain('Processing retailer: B&M')
        ->expectsOutputToContain('All crawl jobs have been dispatched')
        ->assertExitCode(0);

    Bus::assertDispatched(CrawlProductListingsJob::class);
});

test('command skips disabled retailers', function () {
    Retailer::factory()
        ->withCrawler(BMCrawler::class)
        ->create([
            'name' => 'Disabled Store',
            'status' => RetailerStatus::Disabled,
        ]);

    $this->artisan('crawler:dispatch-all')
        ->expectsOutputToContain('No crawlable retailers found')
        ->assertExitCode(0);

    Bus::assertNotDispatched(CrawlProductListingsJob::class);
});

test('command skips paused retailers', function () {
    Retailer::factory()
        ->withCrawler(BMCrawler::class)
        ->create([
            'name' => 'Paused Store',
            'status' => RetailerStatus::Paused,
            'paused_until' => now()->addHour(),
        ]);

    $this->artisan('crawler:dispatch-all')
        ->expectsOutputToContain('No crawlable retailers found')
        ->assertExitCode(0);

    Bus::assertNotDispatched(CrawlProductListingsJob::class);
});

test('command includes active retailers regardless of paused_until field', function () {
    $retailer = Retailer::factory()
        ->withCrawler(BMCrawler::class)
        ->create([
            'name' => 'Active Store',
            'slug' => 'active-store',
            'status' => RetailerStatus::Active,
            'paused_until' => now()->subHour(), // expired pause time, but status is Active
        ]);

    $this->artisan('crawler:dispatch-all')
        ->expectsOutputToContain('Dispatching crawl jobs for 1 retailer(s)')
        ->expectsOutputToContain('Processing retailer: Active Store')
        ->assertExitCode(0);

    Bus::assertDispatched(CrawlProductListingsJob::class);
});

test('command skips retailers without crawler class', function () {
    Retailer::factory()->create([
        'name' => 'No Crawler',
        'crawler_class' => null,
        'status' => RetailerStatus::Active,
    ]);

    $this->artisan('crawler:dispatch-all')
        ->expectsOutputToContain('Skipping No Crawler: Invalid or missing crawler_class')
        ->assertExitCode(0);

    Bus::assertNotDispatched(CrawlProductListingsJob::class);
});

test('command can filter by retailer slug', function () {
    Retailer::factory()
        ->withCrawler(BMCrawler::class)
        ->create(['name' => 'B&M', 'slug' => 'bm']);

    Retailer::factory()
        ->withCrawler(BMCrawler::class)
        ->create(['name' => 'Other Store', 'slug' => 'other']);

    $this->artisan('crawler:dispatch-all --retailer=bm')
        ->expectsOutputToContain('Dispatching crawl jobs for 1 retailer(s)')
        ->expectsOutputToContain('Processing retailer: B&M')
        ->assertExitCode(0);
});

test('command updates last_crawled_at timestamp', function () {
    $retailer = Retailer::factory()
        ->withCrawler(BMCrawler::class)
        ->create(['last_crawled_at' => null]);

    $this->artisan('crawler:dispatch-all')
        ->assertExitCode(0);

    $retailer->refresh();
    expect($retailer->last_crawled_at)->not->toBeNull();
});

test('scheduled crawler command is registered', function () {
    $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();

    $crawlerEvent = collect($events)->first(function ($event) {
        return str_contains($event->command ?? '', 'crawler:dispatch-all');
    });

    expect($crawlerEvent)->not->toBeNull();
    expect($crawlerEvent->timezone)->toBe('Europe/London');
    expect($crawlerEvent->withoutOverlapping)->toBeTrue();
    expect($crawlerEvent->onOneServer)->toBeTrue();
});
