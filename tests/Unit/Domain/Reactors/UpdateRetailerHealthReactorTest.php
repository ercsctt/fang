<?php

declare(strict_types=1);

use App\Domain\Crawler\Aggregates\CrawlAggregate;
use App\Domain\Crawler\Events\CrawlCompleted;
use App\Domain\Crawler\Reactors\UpdateRetailerHealthReactor;
use App\Enums\RetailerHealthStatus;
use App\Models\Retailer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

describe('successful crawl tracking', function () {
    test('records successful crawl result', function () {
        Log::spy();

        $retailer = Retailer::factory()->create(['slug' => 'bm', 'name' => 'B&M']);

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate->completeCrawl(['duration_seconds' => 5.5]);
        $aggregate->persist();

        $metrics = UpdateRetailerHealthReactor::getHealthMetrics('bm');

        expect($metrics)->not->toBeNull()
            ->and($metrics['success_rate'])->toBe(100.0)
            ->and($metrics['total_crawls'])->toBe(1)
            ->and($metrics['successful_crawls'])->toBe(1)
            ->and($metrics['failed_crawls'])->toBe(0);
    });

    test('tracks average duration correctly', function () {
        Log::spy();

        $retailer = Retailer::factory()->create(['slug' => 'bm', 'name' => 'B&M']);

        // First crawl - 5 seconds
        $crawlId1 = Str::uuid()->toString();
        $aggregate1 = CrawlAggregate::retrieve($crawlId1);
        $aggregate1->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate1->completeCrawl(['duration_seconds' => 5.0]);
        $aggregate1->persist();

        // Second crawl - 10 seconds
        $crawlId2 = Str::uuid()->toString();
        $aggregate2 = CrawlAggregate::retrieve($crawlId2);
        $aggregate2->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate2->completeCrawl(['duration_seconds' => 10.0]);
        $aggregate2->persist();

        $metrics = UpdateRetailerHealthReactor::getHealthMetrics('bm');

        expect($metrics['avg_duration_seconds'])->toBe(7.5);
    });

    test('updates last successful crawl timestamp', function () {
        Log::spy();

        $retailer = Retailer::factory()->create(['slug' => 'bm', 'name' => 'B&M']);

        $beforeCrawl = now()->timestamp;

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate->completeCrawl();
        $aggregate->persist();

        $metrics = UpdateRetailerHealthReactor::getHealthMetrics('bm');

        expect($metrics['last_successful_crawl'])->toBeGreaterThanOrEqual($beforeCrawl);
    });
});

describe('failed crawl tracking', function () {
    test('records failed crawl result', function () {
        Log::spy();

        $retailer = Retailer::factory()->create(['slug' => 'bm', 'name' => 'B&M']);

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate->markAsFailed('Test failure');
        $aggregate->persist();

        $metrics = UpdateRetailerHealthReactor::getHealthMetrics('bm');

        expect($metrics)->not->toBeNull()
            ->and($metrics['success_rate'])->toBe(0.0)
            ->and($metrics['total_crawls'])->toBe(1)
            ->and($metrics['successful_crawls'])->toBe(0)
            ->and($metrics['failed_crawls'])->toBe(1);
    });

    test('does not update last successful crawl on failure', function () {
        Log::spy();

        $retailer = Retailer::factory()->create(['slug' => 'bm', 'name' => 'B&M']);

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate->markAsFailed('Test failure');
        $aggregate->persist();

        $metrics = UpdateRetailerHealthReactor::getHealthMetrics('bm');

        expect($metrics['last_successful_crawl'])->toBeNull();
    });
});

describe('mixed crawl outcomes', function () {
    test('calculates success rate correctly with mixed outcomes', function () {
        Log::spy();

        $retailer = Retailer::factory()->create(['slug' => 'bm', 'name' => 'B&M']);

        // 3 successful crawls
        for ($i = 0; $i < 3; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
            $aggregate->completeCrawl();
            $aggregate->persist();
        }

        // 2 failed crawls
        for ($i = 0; $i < 2; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
            $aggregate->markAsFailed('Test failure');
            $aggregate->persist();
        }

        $metrics = UpdateRetailerHealthReactor::getHealthMetrics('bm');

        expect($metrics['success_rate'])->toBe(60.0)
            ->and($metrics['total_crawls'])->toBe(5)
            ->and($metrics['successful_crawls'])->toBe(3)
            ->and($metrics['failed_crawls'])->toBe(2);
    });
});

describe('retailer isolation', function () {
    test('tracks metrics separately per retailer', function () {
        Log::spy();

        $bmRetailer = Retailer::factory()->create(['slug' => 'bm', 'name' => 'B&M']);
        $pahRetailer = Retailer::factory()->create(['slug' => 'pah', 'name' => 'Pets at Home']);

        // B&M - all successful
        for ($i = 0; $i < 3; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
            $aggregate->completeCrawl();
            $aggregate->persist();
        }

        // Pets at Home - all failed
        for ($i = 0; $i < 2; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.petsathome.com/dogs', retailer: 'pah');
            $aggregate->markAsFailed('Test failure');
            $aggregate->persist();
        }

        $bmMetrics = UpdateRetailerHealthReactor::getHealthMetrics('bm');
        $pahMetrics = UpdateRetailerHealthReactor::getHealthMetrics('pah');

        expect($bmMetrics['success_rate'])->toBe(100.0)
            ->and($bmMetrics['total_crawls'])->toBe(3)
            ->and($pahMetrics['success_rate'])->toBe(0.0)
            ->and($pahMetrics['total_crawls'])->toBe(2);
    });
});

describe('circuit breaker - database-backed health tracking', function () {
    test('resets consecutive failures and sets healthy status on successful crawl', function () {
        Log::spy();

        $retailer = Retailer::factory()->create([
            'slug' => 'bm',
            'name' => 'B&M',
            'consecutive_failures' => 7,
            'health_status' => RetailerHealthStatus::Degraded,
            'last_failure_at' => now()->subHour(),
        ]);

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate->completeCrawl();
        $aggregate->persist();

        $retailer->refresh();

        expect($retailer->consecutive_failures)->toBe(0)
            ->and($retailer->health_status)->toBe(RetailerHealthStatus::Healthy)
            ->and($retailer->paused_until)->toBeNull();
    });

    test('clears paused_until on successful crawl', function () {
        Log::spy();

        $retailer = Retailer::factory()->paused()->create([
            'slug' => 'bm',
            'name' => 'B&M',
        ]);

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate->completeCrawl();
        $aggregate->persist();

        $retailer->refresh();

        expect($retailer->paused_until)->toBeNull()
            ->and($retailer->health_status)->toBe(RetailerHealthStatus::Healthy);
    });

    test('increments consecutive failures on failed crawl', function () {
        Log::spy();

        $retailer = Retailer::factory()->create([
            'slug' => 'bm',
            'name' => 'B&M',
            'consecutive_failures' => 2,
        ]);

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate->markAsFailed('Test failure');
        $aggregate->persist();

        $retailer->refresh();

        expect($retailer->consecutive_failures)->toBe(3)
            ->and($retailer->last_failure_at)->not->toBeNull();
    });

    test('sets degraded status after 5 consecutive failures', function () {
        Log::spy();

        $retailer = Retailer::factory()->create([
            'slug' => 'bm',
            'name' => 'B&M',
            'consecutive_failures' => 4,
            'health_status' => RetailerHealthStatus::Healthy,
        ]);

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate->markAsFailed('Test failure');
        $aggregate->persist();

        $retailer->refresh();

        expect($retailer->consecutive_failures)->toBe(5)
            ->and($retailer->health_status)->toBe(RetailerHealthStatus::Degraded);
    });

    test('sets unhealthy status and pauses retailer after 10 consecutive failures', function () {
        Log::spy();

        $retailer = Retailer::factory()->create([
            'slug' => 'bm',
            'name' => 'B&M',
            'consecutive_failures' => 9,
            'health_status' => RetailerHealthStatus::Degraded,
        ]);

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate->markAsFailed('Test failure');
        $aggregate->persist();

        $retailer->refresh();

        expect($retailer->consecutive_failures)->toBe(10)
            ->and($retailer->health_status)->toBe(RetailerHealthStatus::Unhealthy)
            ->and($retailer->paused_until)->not->toBeNull()
            ->and($retailer->paused_until->isFuture())->toBeTrue();
    });

    test('logs error when circuit breaker is activated', function () {
        Log::spy();

        $retailer = Retailer::factory()->create([
            'slug' => 'bm',
            'name' => 'B&M',
            'consecutive_failures' => 9,
            'health_status' => RetailerHealthStatus::Degraded,
        ]);

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate->markAsFailed('Test failure');
        $aggregate->persist();

        Log::shouldHaveReceived('error')
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'CIRCUIT BREAKER ACTIVATED')
                    && isset($context['consecutive_failures'])
                    && $context['consecutive_failures'] === 10;
            })
            ->once();
    });

    test('does not extend pause duration if already paused', function () {
        Log::spy();

        $originalPausedUntil = now()->addMinutes(30);
        $retailer = Retailer::factory()->create([
            'slug' => 'bm',
            'name' => 'B&M',
            'consecutive_failures' => 10,
            'health_status' => RetailerHealthStatus::Unhealthy,
            'paused_until' => $originalPausedUntil,
        ]);

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate->markAsFailed('Test failure');
        $aggregate->persist();

        $retailer->refresh();

        // Should still be unhealthy with incremented failures but same pause time
        expect($retailer->consecutive_failures)->toBe(11)
            ->and($retailer->health_status)->toBe(RetailerHealthStatus::Unhealthy)
            ->and($retailer->paused_until->timestamp)->toBe($originalPausedUntil->timestamp);
    });

    test('manual reset clears all health tracking', function () {
        $retailer = Retailer::factory()->paused()->create([
            'slug' => 'bm',
            'name' => 'B&M',
            'last_failure_at' => now()->subHour(),
        ]);

        // Add some cache data
        Cache::put('crawler:health:results:bm', [['success' => false, 'timestamp' => now()->timestamp]]);
        Cache::put('crawler:health:metrics:bm', ['success_rate' => 0.0]);

        UpdateRetailerHealthReactor::resetHealth('bm');

        $retailer->refresh();

        expect($retailer->consecutive_failures)->toBe(0)
            ->and($retailer->health_status)->toBe(RetailerHealthStatus::Healthy)
            ->and($retailer->paused_until)->toBeNull()
            ->and($retailer->last_failure_at)->toBeNull()
            ->and(Cache::get('crawler:health:results:bm'))->toBeNull()
            ->and(Cache::get('crawler:health:metrics:bm'))->toBeNull();
    });
});

describe('Retailer model health methods', function () {
    test('isPaused returns true when paused_until is in the future', function () {
        $retailer = Retailer::factory()->create([
            'paused_until' => now()->addHour(),
        ]);

        expect($retailer->isPaused())->toBeTrue();
    });

    test('isPaused returns false when paused_until is in the past', function () {
        $retailer = Retailer::factory()->create([
            'paused_until' => now()->subHour(),
        ]);

        expect($retailer->isPaused())->toBeFalse();
    });

    test('isPaused returns false when paused_until is null', function () {
        $retailer = Retailer::factory()->create([
            'paused_until' => null,
        ]);

        expect($retailer->isPaused())->toBeFalse();
    });

    test('isAvailableForCrawling returns true when active and not paused', function () {
        $retailer = Retailer::factory()->create([
            'is_active' => true,
            'paused_until' => null,
        ]);

        expect($retailer->isAvailableForCrawling())->toBeTrue();
    });

    test('isAvailableForCrawling returns false when inactive', function () {
        $retailer = Retailer::factory()->inactive()->create([
            'paused_until' => null,
        ]);

        expect($retailer->isAvailableForCrawling())->toBeFalse();
    });

    test('isAvailableForCrawling returns false when paused', function () {
        $retailer = Retailer::factory()->paused()->create();

        expect($retailer->isAvailableForCrawling())->toBeFalse();
    });
});

describe('edge cases', function () {
    test('handles missing retailer in database gracefully', function () {
        Log::shouldReceive('warning')
            ->atLeast()
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'Retailer not found');
            });
        Log::shouldReceive('debug')->andReturnNull();

        // Create the crawl start event but no matching retailer in DB
        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.unknown.co.uk/pets', retailer: 'unknown-retailer');
        $aggregate->completeCrawl();
        $aggregate->persist();

        // Should not throw, just log warning
        expect(true)->toBeTrue();
    });

    test('handles missing crawl start event gracefully', function () {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'Could not determine retailer');
            });

        $reactor = new UpdateRetailerHealthReactor;
        $event = new CrawlCompleted(
            crawlId: 'non-existent-crawl-id',
            productListingsDiscovered: 0,
            statistics: [],
        );

        $reactor->onCrawlCompleted($event);

        expect(UpdateRetailerHealthReactor::getHealthMetrics('Unknown'))->toBeNull();
    });

    test('returns null for unknown retailer metrics', function () {
        expect(UpdateRetailerHealthReactor::getHealthMetrics('Unknown Retailer'))->toBeNull();
    });
});
