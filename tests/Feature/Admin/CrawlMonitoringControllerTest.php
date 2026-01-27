<?php

declare(strict_types=1);

use App\Enums\MatchType;
use App\Models\CrawlStatistic;
use App\Models\Product;
use App\Models\ProductListing;
use App\Models\ProductListingMatch;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
});

test('crawl monitoring page requires authentication', function () {
    $response = $this->get('/admin/crawl-monitoring');

    $response->assertRedirect('/login');
});

test('crawl monitoring page loads for authenticated users', function () {
    $response = $this->actingAs($this->user)->get('/admin/crawl-monitoring');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/CrawlMonitoring/Index')
            ->has('retailers')
            ->has('statistics')
            ->has('todayStats')
            ->has('matchingStats')
            ->has('dataFreshnessStats')
            ->has('failedJobs')
            ->has('chartData')
            ->has('filters')
        );
});

test('crawl monitoring page shows retailers with health status', function () {
    $healthyRetailer = Retailer::factory()->create(['name' => 'Healthy Store']);
    $degradedRetailer = Retailer::factory()->degraded()->create(['name' => 'Degraded Store']);
    $unhealthyRetailer = Retailer::factory()->unhealthy()->create(['name' => 'Unhealthy Store']);

    $response = $this->actingAs($this->user)->get('/admin/crawl-monitoring');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/CrawlMonitoring/Index')
            ->has('retailers', 3)
        );
});

test('crawl monitoring page shows crawl statistics for selected date range', function () {
    $retailer = Retailer::factory()->create();

    CrawlStatistic::factory()
        ->for($retailer)
        ->forDate(now()->subDays(5)->toDateString())
        ->create();

    CrawlStatistic::factory()
        ->for($retailer)
        ->forDate(now()->subDays(10)->toDateString())
        ->create();

    $response = $this->actingAs($this->user)->get('/admin/crawl-monitoring?range=7');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/CrawlMonitoring/Index')
            ->has('statistics', 1)
            ->where('filters.range', '7')
        );

    $response14 = $this->actingAs($this->user)->get('/admin/crawl-monitoring?range=14');

    $response14->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('statistics', 2)
            ->where('filters.range', '14')
        );
});

test('today stats are calculated correctly', function () {
    $retailer1 = Retailer::factory()->create();
    $retailer2 = Retailer::factory()->create();

    CrawlStatistic::factory()
        ->for($retailer1)
        ->forDate(today()->toDateString())
        ->create([
            'crawls_started' => 50,
            'crawls_completed' => 45,
            'crawls_failed' => 5,
            'listings_discovered' => 100,
            'details_extracted' => 90,
        ]);

    CrawlStatistic::factory()
        ->for($retailer2)
        ->forDate(today()->toDateString())
        ->create([
            'crawls_started' => 30,
            'crawls_completed' => 25,
            'crawls_failed' => 5,
            'listings_discovered' => 50,
            'details_extracted' => 40,
        ]);

    $response = $this->actingAs($this->user)->get('/admin/crawl-monitoring');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('todayStats.crawls_started', 80)
            ->where('todayStats.crawls_completed', 70)
            ->where('todayStats.crawls_failed', 10)
            ->where('todayStats.listings_discovered', 150)
            ->where('todayStats.details_extracted', 130)
            ->where('todayStats.success_rate', 87.5)
        );
});

test('matching stats are calculated correctly', function () {
    $retailer = Retailer::factory()->create();

    $product1 = Product::factory()->create();
    $product2 = Product::factory()->create();

    $listing1 = ProductListing::factory()->for($retailer)->create();
    $listing2 = ProductListing::factory()->for($retailer)->create();
    $listing3 = ProductListing::factory()->for($retailer)->create();

    ProductListingMatch::factory()->create([
        'product_id' => $product1->id,
        'product_listing_id' => $listing1->id,
        'match_type' => MatchType::Exact,
    ]);

    ProductListingMatch::factory()->create([
        'product_id' => $product2->id,
        'product_listing_id' => $listing2->id,
        'match_type' => MatchType::Fuzzy,
    ]);

    $response = $this->actingAs($this->user)->get('/admin/crawl-monitoring');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('matchingStats.exact', 1)
            ->where('matchingStats.fuzzy', 1)
            ->where('matchingStats.barcode', 0)
            ->where('matchingStats.manual', 0)
            ->where('matchingStats.unmatched', 1)
            ->where('matchingStats.total_listings', 3)
        );
});

test('data freshness stats are calculated correctly', function () {
    $retailer = Retailer::factory()->create();

    ProductListing::factory()
        ->for($retailer)
        ->create(['last_scraped_at' => now()->subHours(12)]);

    ProductListing::factory()
        ->for($retailer)
        ->create(['last_scraped_at' => now()->subHours(36)]);

    ProductListing::factory()
        ->for($retailer)
        ->create(['last_scraped_at' => now()->subDays(3)]);

    ProductListing::factory()
        ->for($retailer)
        ->create(['last_scraped_at' => now()->subDays(10)]);

    ProductListing::factory()
        ->for($retailer)
        ->create(['last_scraped_at' => null]);

    $response = $this->actingAs($this->user)->get('/admin/crawl-monitoring');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dataFreshnessStats.fresh', 1)
            ->where('dataFreshnessStats.stale_24h', 1)
            ->where('dataFreshnessStats.stale_48h', 1)
            ->where('dataFreshnessStats.stale_week', 1)
            ->where('dataFreshnessStats.never_scraped', 1)
            ->where('dataFreshnessStats.total', 5)
        );
});

test('failed jobs are returned in the response', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => 'test-uuid-1',
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\TestJob']),
        'exception' => 'TestException: Something went wrong',
        'failed_at' => now(),
    ]);

    $response = $this->actingAs($this->user)->get('/admin/crawl-monitoring');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('failedJobs', 1)
            ->where('failedJobs.0.uuid', 'test-uuid-1')
            ->where('failedJobs.0.queue', 'default')
            ->where('failedJobs.0.payload_summary', 'App\\Jobs\\TestJob')
        );
});

test('chart data includes all days in the range', function () {
    $retailer = Retailer::factory()->create();

    CrawlStatistic::factory()
        ->for($retailer)
        ->forDate(now()->subDays(3)->toDateString())
        ->create([
            'crawls_completed' => 10,
            'crawls_failed' => 2,
            'listings_discovered' => 50,
        ]);

    $response = $this->actingAs($this->user)->get('/admin/crawl-monitoring?range=7');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('chartData.labels', 7)
            ->has('chartData.datasets.crawls', 7)
            ->has('chartData.datasets.listings', 7)
            ->has('chartData.datasets.failures', 7)
        );
});

test('retry job endpoint requires authentication', function () {
    $response = $this->postJson('/admin/crawl-monitoring/jobs/1/retry');

    $response->assertUnauthorized();
});

test('retry job endpoint queues failed job for retry', function () {
    $jobId = DB::table('failed_jobs')->insertGetId([
        'uuid' => 'test-uuid-retry',
        'connection' => 'redis',
        'queue' => 'crawler',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\CrawlJob']),
        'exception' => 'TestException: Connection failed',
        'failed_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/admin/crawl-monitoring/jobs/{$jobId}/retry");

    $response->assertOk()
        ->assertJson(['message' => 'Job queued for retry']);

    $this->assertDatabaseMissing('failed_jobs', ['id' => $jobId]);
    $this->assertDatabaseHas('jobs', ['queue' => 'crawler']);
});

test('retry job endpoint returns 404 for non-existent job', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/admin/crawl-monitoring/jobs/99999/retry');

    $response->assertNotFound()
        ->assertJson(['message' => 'Job not found']);
});

test('delete job endpoint requires authentication', function () {
    $response = $this->deleteJson('/admin/crawl-monitoring/jobs/1');

    $response->assertUnauthorized();
});

test('delete job endpoint removes failed job', function () {
    $jobId = DB::table('failed_jobs')->insertGetId([
        'uuid' => 'test-uuid-delete',
        'connection' => 'redis',
        'queue' => 'crawler',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\CrawlJob']),
        'exception' => 'TestException: Connection failed',
        'failed_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson("/admin/crawl-monitoring/jobs/{$jobId}");

    $response->assertOk()
        ->assertJson(['message' => 'Job deleted']);

    $this->assertDatabaseMissing('failed_jobs', ['id' => $jobId]);
});

test('delete job endpoint returns 404 for non-existent job', function () {
    $response = $this->actingAs($this->user)
        ->deleteJson('/admin/crawl-monitoring/jobs/99999');

    $response->assertNotFound()
        ->assertJson(['message' => 'Job not found']);
});

test('retry all jobs endpoint requires authentication', function () {
    $response = $this->postJson('/admin/crawl-monitoring/jobs/retry-all');

    $response->assertUnauthorized();
});

test('retry all jobs endpoint queues all failed jobs for retry', function () {
    DB::table('failed_jobs')->insert([
        [
            'uuid' => 'test-uuid-all-1',
            'connection' => 'redis',
            'queue' => 'crawler',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\CrawlJob1']),
            'exception' => 'Error 1',
            'failed_at' => now(),
        ],
        [
            'uuid' => 'test-uuid-all-2',
            'connection' => 'redis',
            'queue' => 'crawler',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\CrawlJob2']),
            'exception' => 'Error 2',
            'failed_at' => now(),
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/admin/crawl-monitoring/jobs/retry-all');

    $response->assertOk()
        ->assertJson([
            'message' => 'All jobs queued for retry',
            'count' => 2,
        ]);

    $this->assertDatabaseCount('failed_jobs', 0);
    $this->assertDatabaseCount('jobs', 2);
});

test('paused retailers are correctly identified', function () {
    Retailer::factory()->create(['name' => 'Active Store']);
    Retailer::factory()->paused()->create(['name' => 'Paused Store']);

    $response = $this->actingAs($this->user)->get('/admin/crawl-monitoring');

    $response->assertOk();

    $inertiaProps = $response->original->getData()['page']['props'];
    $retailers = collect($inertiaProps['retailers']);

    $activeRetailer = $retailers->firstWhere('name', 'Active Store');
    $pausedRetailer = $retailers->firstWhere('name', 'Paused Store');

    expect($activeRetailer['is_paused'])->toBeFalse();
    expect($pausedRetailer['is_paused'])->toBeTrue();
});

test('retailer availability for crawling is correctly determined', function () {
    Retailer::factory()->create(['name' => 'Available Store', 'is_active' => true]);
    Retailer::factory()->inactive()->create(['name' => 'Inactive Store']);
    Retailer::factory()->paused()->create(['name' => 'Paused Store']);

    $response = $this->actingAs($this->user)->get('/admin/crawl-monitoring');

    $response->assertOk();

    $inertiaProps = $response->original->getData()['page']['props'];
    $retailers = collect($inertiaProps['retailers']);

    $availableRetailer = $retailers->firstWhere('name', 'Available Store');
    $inactiveRetailer = $retailers->firstWhere('name', 'Inactive Store');
    $pausedRetailer = $retailers->firstWhere('name', 'Paused Store');

    expect($availableRetailer['is_available_for_crawling'])->toBeTrue();
    expect($inactiveRetailer['is_available_for_crawling'])->toBeFalse();
    expect($pausedRetailer['is_available_for_crawling'])->toBeFalse();
});
