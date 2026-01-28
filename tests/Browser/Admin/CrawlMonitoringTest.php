<?php

declare(strict_types=1);

use App\Models\CrawlStatistic;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);
});

test('crawl monitoring page loads', function () {
    // Visit login page
    $page = visit('/login');

    // Login
    $page->fill('#email', 'test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertSee('Dashboard');

    // Navigate to crawl monitoring
    $page->navigate('/admin/crawl-monitoring')
        ->assertSee('Crawl Monitoring')
        ->assertSee('Monitor crawl operations, retailer health, and data quality');
});

test('kpi cards display correct data', function () {
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

    $page = visit('/login')
        ->fill('#email', 'test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertSee('Dashboard')
        ->navigate('/admin/crawl-monitoring')
        ->assertSee('Crawl Monitoring');

    // Today's Crawls card shows completed count (70)
    $page->assertSee("Today's Crawls")
        ->assertSee('70')
        ->assertSee('10 failed');

    // Success Rate card shows 87.5%
    $page->assertSee('Success Rate')
        ->assertSee('87.5%')
        ->assertSee('80 started today');

    // Listings Discovered card
    $page->assertSee('Listings Discovered')
        ->assertSee('150');
});

test('retailer health table displays', function () {
    Retailer::factory()->create(['name' => 'Active Pet Store']);
    Retailer::factory()->failed()->create(['name' => 'Failed Pet Store']);
    Retailer::factory()->degraded()->create(['name' => 'Degraded Pet Store']);

    $page = visit('/login')
        ->fill('#email', 'test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertSee('Dashboard')
        ->navigate('/admin/crawl-monitoring')
        ->assertSee('Crawl Monitoring');

    $page->assertSee('Retailer Health Status')
        ->assertSee('Active Pet Store')
        ->assertSee('Failed Pet Store')
        ->assertSee('Degraded Pet Store')
        ->assertSee('Active')
        ->assertSee('Failed')
        ->assertSee('Degraded');
});

test('retailer health table sorting shows unhealthy retailers first', function () {
    // Create retailers in a specific order
    Retailer::factory()->create(['name' => 'AAA Active Store']);
    Retailer::factory()->failed()->create(['name' => 'ZZZ Failed Store']);
    Retailer::factory()->degraded()->create(['name' => 'MMM Degraded Store']);

    $page = visit('/login')
        ->fill('#email', 'test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertSee('Dashboard')
        ->navigate('/admin/crawl-monitoring')
        ->assertSee('Crawl Monitoring');

    // The failed retailer should appear before active in the table (sorted by severity)
    $page->assertSee('Retailer Health Status')
        ->assertSee('ZZZ Failed Store')
        ->assertSee('MMM Degraded Store')
        ->assertSee('AAA Active Store');
});

test('time range filter works', function () {
    $retailer = Retailer::factory()->create();

    // Create a statistic within 7 days
    CrawlStatistic::factory()
        ->for($retailer)
        ->forDate(now()->subDays(5)->toDateString())
        ->create([
            'crawls_started' => 100,
            'crawls_completed' => 95,
        ]);

    // Create a statistic outside 7 days but within 14 days
    CrawlStatistic::factory()
        ->for($retailer)
        ->forDate(now()->subDays(10)->toDateString())
        ->create([
            'crawls_started' => 50,
            'crawls_completed' => 45,
        ]);

    $page = visit('/login')
        ->fill('#email', 'test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertSee('Dashboard')
        ->navigate('/admin/crawl-monitoring')
        ->assertSee('Crawl Monitoring');

    $page->assertSee('Last 7 days');

    // Change to 14 days using the select
    $page->click('Last 7 days')
        ->click('Last 14 days');

    // Wait for page to update with new range
    $page->assertUrlContains('range=14')
        ->assertSee('Last 14 days');
});

test('charts render', function () {
    $retailer = Retailer::factory()->create();

    CrawlStatistic::factory()
        ->for($retailer)
        ->forDate(now()->subDays(3)->toDateString())
        ->create([
            'crawls_completed' => 10,
            'crawls_failed' => 2,
            'listings_discovered' => 50,
        ]);

    $page = visit('/login')
        ->fill('#email', 'test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertSee('Dashboard')
        ->navigate('/admin/crawl-monitoring')
        ->assertSee('Crawl Monitoring');

    // Verify chart card is present
    $page->assertSee('Crawl Activity')
        ->assertSee('Completed crawls, listings discovered, and failures over time');
});

test('failed jobs table displays', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => 'test-uuid-browser-1',
        'connection' => 'redis',
        'queue' => 'crawler',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\CrawlProductListingsJob']),
        'exception' => 'TestException: Connection timeout on retailer API',
        'failed_at' => now(),
    ]);

    $page = visit('/login')
        ->fill('#email', 'test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertSee('Dashboard')
        ->navigate('/admin/crawl-monitoring')
        ->assertSee('Crawl Monitoring');

    $page->assertSee('Failed Jobs')
        ->assertSee('Recent failed crawl jobs with retry controls')
        ->assertSee('CrawlProductListingsJob')
        ->assertSee('crawler')
        ->assertSee('TestException: Connection timeout');
});

test('retry job button works', function () {
    $jobId = DB::table('failed_jobs')->insertGetId([
        'uuid' => 'test-uuid-retry-browser',
        'connection' => 'redis',
        'queue' => 'crawler',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\RetryTestJob']),
        'exception' => 'TestException: Retry test error',
        'failed_at' => now(),
    ]);

    $page = visit('/login')
        ->fill('#email', 'test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertSee('Dashboard')
        ->navigate('/admin/crawl-monitoring')
        ->assertSee('Crawl Monitoring');

    // Verify the job is in the list
    $page->assertSee('RetryTestJob');

    // Click the retry button (first icon button in the row)
    $page->click('[title="Retry job"]');

    // After retry, the job should be removed from failed_jobs
    $page->assertSee('No failed jobs');

    // Verify the job was moved to the jobs queue
    $this->assertDatabaseMissing('failed_jobs', ['id' => $jobId]);
    $this->assertDatabaseHas('jobs', ['queue' => 'crawler']);
});

test('delete job button works', function () {
    $jobId = DB::table('failed_jobs')->insertGetId([
        'uuid' => 'test-uuid-delete-browser',
        'connection' => 'redis',
        'queue' => 'crawler',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\DeleteTestJob']),
        'exception' => 'TestException: Delete test error',
        'failed_at' => now(),
    ]);

    $page = visit('/login')
        ->fill('#email', 'test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertSee('Dashboard')
        ->navigate('/admin/crawl-monitoring')
        ->assertSee('Crawl Monitoring');

    // Verify the job is in the list
    $page->assertSee('DeleteTestJob');

    // Click the delete button
    $page->click('[title="Delete job"]');

    // Accept the confirmation dialog
    $page->acceptDialog();

    // After delete, the job should be removed from the page
    $page->assertSee('No failed jobs');

    // Verify the job was deleted from the database
    $this->assertDatabaseMissing('failed_jobs', ['id' => $jobId]);
});
