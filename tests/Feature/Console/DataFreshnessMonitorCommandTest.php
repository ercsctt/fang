<?php

declare(strict_types=1);

use App\Enums\RetailerStatus;
use App\Models\ProductListing;
use App\Models\Retailer;
use App\Notifications\DataFreshnessAlertNotification;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\artisan;

beforeEach(function () {
    Notification::fake();
    \Illuminate\Support\Facades\Cache::flush();

    config([
        'monitoring.stale_product_threshold_days' => 2,
        'monitoring.retailer_crawl_threshold_hours' => 24,
        'monitoring.high_failure_rate_threshold' => 0.2,
        'monitoring.notification_channels' => ['mail'],
        'monitoring.alert_email' => 'alerts@example.com',
    ]);
});

describe('DataFreshnessMonitorCommand', function () {
    test('passes when all data is fresh', function () {
        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'last_crawled_at' => now()->subHours(12),
        ]);

        ProductListing::factory()->count(5)->create([
            'retailer_id' => $retailer->id,
            'last_scraped_at' => now()->subHours(12),
        ]);

        artisan('monitor:data-freshness')
            ->expectsOutputToContain('All systems healthy')
            ->assertExitCode(0);

        Notification::assertNothingSent();
    });

    test('detects stale products not scraped in threshold days', function () {
        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'last_crawled_at' => now()->subHours(1),
        ]);

        for ($i = 0; $i < 150; $i++) {
            ProductListing::factory()->create([
                'retailer_id' => $retailer->id,
                'url' => 'https://example.com/stale-product-'.$i,
                'last_scraped_at' => now()->subDays(5),
            ]);
        }

        artisan('monitor:data-freshness --alert')
            ->expectsOutputToContain('critical issue')
            ->assertExitCode(1);

        Notification::assertSentOnDemand(DataFreshnessAlertNotification::class, function ($notification) {
            expect($notification->criticalIssues)->toHaveKey('stale_products')
                ->and($notification->criticalIssues['stale_products']['total'])->toBe(150)
                ->and($notification->criticalIssues['stale_products']['threshold_days'])->toBe(2);

            return true;
        });
    });

    test('detects retailers with no successful crawls in threshold hours', function () {
        Retailer::factory()->count(2)->create([
            'status' => RetailerStatus::Active,
            'last_crawled_at' => now()->subDays(3),
        ]);

        artisan('monitor:data-freshness --alert')
            ->expectsOutputToContain('critical issue')
            ->assertExitCode(1);

        Notification::assertSentOnDemand(DataFreshnessAlertNotification::class, function ($notification) {
            expect($notification->criticalIssues)->toHaveKey('inactive_retailers')
                ->and($notification->criticalIssues['inactive_retailers']['total'])->toBe(2)
                ->and($notification->criticalIssues['inactive_retailers']['threshold_hours'])->toBe(24);

            return true;
        });
    });

    test('detects high failure rates per retailer', function () {
        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'last_crawled_at' => now()->subHours(1),
        ]);

        ProductListing::factory()->count(80)->create([
            'retailer_id' => $retailer->id,
            'last_scraped_at' => now()->subDays(3),
        ]);

        ProductListing::factory()->count(20)->create([
            'retailer_id' => $retailer->id,
            'last_scraped_at' => now()->subHours(1),
        ]);

        artisan('monitor:data-freshness --alert')
            ->expectsOutputToContain('critical issue')
            ->assertExitCode(1);

        Notification::assertSentOnDemand(DataFreshnessAlertNotification::class, function ($notification) {
            expect($notification->criticalIssues)->toHaveKey('high_failure_retailers')
                ->and($notification->criticalIssues['high_failure_retailers']['total'])->toBeGreaterThan(0);

            return true;
        });
    });

    test('skips disabled retailers when checking for inactive crawls', function () {
        Retailer::factory()->disabled()->create([
            'last_crawled_at' => now()->subDays(10),
        ]);

        Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'last_crawled_at' => now()->subHours(1),
        ]);

        artisan('monitor:data-freshness')
            ->expectsOutputToContain('All systems healthy')
            ->assertExitCode(0);
    });

    test('displays detailed report with --report flag', function () {
        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'last_crawled_at' => now()->subDays(2),
        ]);

        ProductListing::factory()->count(5)->create([
            'retailer_id' => $retailer->id,
            'last_scraped_at' => now()->subDays(5),
        ]);

        artisan('monitor:data-freshness --report')
            ->expectsOutputToContain('Stale Products Report')
            ->expectsOutputToContain('Inactive Retailers Report')
            ->expectsOutputToContain('High Failure Rate Report');
    });

    test('sends notifications only when --alert flag is set', function () {
        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'last_crawled_at' => now()->subDays(3),
        ]);

        for ($i = 0; $i < 150; $i++) {
            ProductListing::factory()->create([
                'retailer_id' => $retailer->id,
                'url' => 'https://example.com/alert-test-'.$i,
                'last_scraped_at' => now()->subDays(5),
            ]);
        }

        artisan('monitor:data-freshness')
            ->assertExitCode(1);

        Notification::assertNothingSent();

        artisan('monitor:data-freshness --alert')
            ->assertExitCode(1);

        Notification::assertSentOnDemand(DataFreshnessAlertNotification::class);
    });

    test('groups stale products by retailer', function () {
        $retailer1 = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'last_crawled_at' => now()->subHours(1),
        ]);
        $retailer2 = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'last_crawled_at' => now()->subHours(1),
        ]);

        for ($i = 0; $i < 120; $i++) {
            ProductListing::factory()->create([
                'retailer_id' => $retailer1->id,
                'url' => 'https://example.com/r1/product-'.$i,
                'last_scraped_at' => now()->subDays(5),
            ]);
        }

        for ($i = 0; $i < 80; $i++) {
            ProductListing::factory()->create([
                'retailer_id' => $retailer2->id,
                'url' => 'https://example.com/r2/product-'.$i,
                'last_scraped_at' => now()->subDays(4),
            ]);
        }

        artisan('monitor:data-freshness --alert --report')
            ->expectsOutputToContain('Stale Products Report')
            ->assertExitCode(1);

        Notification::assertSentOnDemand(DataFreshnessAlertNotification::class, function ($notification) {
            expect($notification->criticalIssues['stale_products']['total'])->toBe(200)
                ->and($notification->criticalIssues['stale_products']['by_retailer'])->toHaveCount(2);

            return true;
        });
    });

    test('handles products never scraped', function () {
        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'last_crawled_at' => now(),
        ]);

        for ($i = 0; $i < 120; $i++) {
            ProductListing::factory()->create([
                'retailer_id' => $retailer->id,
                'url' => 'https://example.com/never-scraped-'.$i,
                'last_scraped_at' => null,
            ]);
        }

        artisan('monitor:data-freshness --alert')
            ->assertExitCode(1);

        Notification::assertSentOnDemand(DataFreshnessAlertNotification::class, function ($notification) {
            expect($notification->criticalIssues)->toHaveKey('stale_products')
                ->and($notification->criticalIssues['stale_products']['total'])->toBe(120);

            return true;
        });
    });

    test('handles retailers never crawled', function () {
        Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'last_crawled_at' => null,
        ]);

        artisan('monitor:data-freshness --alert')
            ->assertExitCode(1);

        Notification::assertSentOnDemand(DataFreshnessAlertNotification::class, function ($notification) {
            expect($notification->criticalIssues)->toHaveKey('inactive_retailers')
                ->and($notification->criticalIssues['inactive_retailers']['total'])->toBe(1);

            return true;
        });
    });

    test('respects configurable thresholds', function () {
        config([
            'monitoring.stale_product_threshold_days' => 10,
            'monitoring.high_failure_rate_threshold' => 1.1,
        ]);

        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'last_crawled_at' => now()->subHours(1),
        ]);

        for ($i = 0; $i < 150; $i++) {
            ProductListing::factory()->create([
                'retailer_id' => $retailer->id,
                'url' => 'https://example.com/product-'.$i,
                'last_scraped_at' => now()->subDays(5),
            ]);
        }

        artisan('monitor:data-freshness')
            ->expectsOutputToContain('All systems healthy')
            ->assertExitCode(0);

        Notification::assertNothingSent();
    });

    test('does not trigger alert for stale products below critical threshold', function () {
        config([
            'monitoring.high_failure_rate_threshold' => 1.1,
        ]);

        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'last_crawled_at' => now()->subHours(1),
        ]);

        for ($i = 0; $i < 50; $i++) {
            ProductListing::factory()->create([
                'retailer_id' => $retailer->id,
                'url' => 'https://example.com/product-'.$i,
                'last_scraped_at' => now()->subDays(5),
            ]);
        }

        artisan('monitor:data-freshness --alert')
            ->expectsOutputToContain('All systems healthy')
            ->assertExitCode(0);

        Notification::assertNothingSent();
    });

    test('skips notifications when no channels configured', function () {
        config(['monitoring.notification_channels' => []]);

        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'last_crawled_at' => now()->subDays(3),
        ]);

        for ($i = 0; $i < 150; $i++) {
            ProductListing::factory()->create([
                'retailer_id' => $retailer->id,
                'url' => 'https://example.com/no-channel-product-'.$i,
                'last_scraped_at' => now()->subDays(5),
            ]);
        }

        artisan('monitor:data-freshness --alert')
            ->expectsOutputToContain('No notification channels configured')
            ->assertExitCode(1);

        Notification::assertNothingSent();
    });
});
