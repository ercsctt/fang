<?php

declare(strict_types=1);

use App\Domain\Crawler\Aggregates\CrawlAggregate;
use App\Domain\Crawler\Events\CrawlCompleted;
use App\Domain\Crawler\Events\CrawlFailed;
use App\Domain\Crawler\Projectors\CrawlStatisticsProjector;
use App\Models\CrawlStatistic;
use App\Models\Retailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(Illuminate\Foundation\Testing\LazilyRefreshDatabase::class);

describe('crawl started tracking', function () {
    test('increments crawls_started when crawl starts', function () {
        Log::spy();

        $retailer = Retailer::factory()->create(['slug' => 'bm', 'name' => 'B&M']);

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate->persist();

        $statistic = CrawlStatistic::query()
            ->where('retailer_id', $retailer->id)
            ->where('date', today())
            ->first();

        expect($statistic)->not->toBeNull()
            ->and($statistic->crawls_started)->toBe(1)
            ->and($statistic->crawls_completed)->toBe(0)
            ->and($statistic->crawls_failed)->toBe(0);
    });

    test('increments crawls_started for multiple crawls on same day', function () {
        Log::spy();

        $retailer = Retailer::factory()->create(['slug' => 'bm', 'name' => 'B&M']);

        for ($i = 0; $i < 3; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
            $aggregate->persist();
        }

        $statistic = CrawlStatistic::query()
            ->where('retailer_id', $retailer->id)
            ->where('date', today())
            ->first();

        expect($statistic->crawls_started)->toBe(3);
    });

    test('logs warning when retailer not found for crawl start', function () {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'Retailer not found')
                    && $context['retailer'] === 'unknown-retailer';
            });

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.unknown.co.uk/pets', retailer: 'unknown-retailer');
        $aggregate->persist();

        $count = CrawlStatistic::count();
        expect($count)->toBe(0);
    });
});

describe('crawl completed tracking', function () {
    test('increments crawls_completed when crawl completes', function () {
        Log::spy();

        $retailer = Retailer::factory()->create(['slug' => 'bm', 'name' => 'B&M']);

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate->completeCrawl(['duration_ms' => 5000]);
        $aggregate->persist();

        $statistic = CrawlStatistic::query()
            ->where('retailer_id', $retailer->id)
            ->where('date', today())
            ->first();

        expect($statistic)->not->toBeNull()
            ->and($statistic->crawls_started)->toBe(1)
            ->and($statistic->crawls_completed)->toBe(1)
            ->and($statistic->crawls_failed)->toBe(0);
    });

    test('tracks listings_discovered from completed crawl', function () {
        Log::spy();

        $retailer = Retailer::factory()->create(['slug' => 'bm', 'name' => 'B&M']);

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');

        for ($i = 0; $i < 5; $i++) {
            $aggregate->recordProductListingDiscovered(
                url: "https://www.bmstores.co.uk/product/{$i}",
                retailer: 'bm',
                category: 'dog-food'
            );
        }

        $aggregate->completeCrawl();
        $aggregate->persist();

        $statistic = CrawlStatistic::query()
            ->where('retailer_id', $retailer->id)
            ->where('date', today())
            ->first();

        expect($statistic->listings_discovered)->toBe(5);
    });

    test('tracks average duration correctly', function () {
        Log::spy();

        $retailer = Retailer::factory()->create(['slug' => 'bm', 'name' => 'B&M']);

        // First crawl - 5000ms
        $crawlId1 = Str::uuid()->toString();
        $aggregate1 = CrawlAggregate::retrieve($crawlId1);
        $aggregate1->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate1->completeCrawl(['duration_ms' => 5000]);
        $aggregate1->persist();

        $statistic = CrawlStatistic::query()
            ->where('retailer_id', $retailer->id)
            ->where('date', today())
            ->first();

        expect($statistic->average_duration_ms)->toBe(5000);

        // Second crawl - 10000ms (average should be 7500ms)
        $crawlId2 = Str::uuid()->toString();
        $aggregate2 = CrawlAggregate::retrieve($crawlId2);
        $aggregate2->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate2->completeCrawl(['duration_ms' => 10000]);
        $aggregate2->persist();

        $statistic->refresh();

        expect($statistic->average_duration_ms)->toBe(7500);
    });

    test('handles null duration gracefully', function () {
        Log::spy();

        $retailer = Retailer::factory()->create(['slug' => 'bm', 'name' => 'B&M']);

        // First crawl with duration
        $crawlId1 = Str::uuid()->toString();
        $aggregate1 = CrawlAggregate::retrieve($crawlId1);
        $aggregate1->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate1->completeCrawl(['duration_ms' => 5000]);
        $aggregate1->persist();

        // Second crawl without duration
        $crawlId2 = Str::uuid()->toString();
        $aggregate2 = CrawlAggregate::retrieve($crawlId2);
        $aggregate2->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate2->completeCrawl([]);
        $aggregate2->persist();

        $statistic = CrawlStatistic::query()
            ->where('retailer_id', $retailer->id)
            ->where('date', today())
            ->first();

        // Average should remain 5000 since second crawl had no duration
        expect($statistic->average_duration_ms)->toBe(5000);
    });

    test('logs warning when crawl start event not found for completion', function () {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'Could not determine retailer for completed crawl');
            });

        $projector = new CrawlStatisticsProjector;
        $event = new CrawlCompleted(
            crawlId: 'non-existent-crawl-id',
            productListingsDiscovered: 0,
            statistics: [],
        );

        $projector->onCrawlCompleted($event);

        $count = CrawlStatistic::count();
        expect($count)->toBe(0);
    });
});

describe('crawl failed tracking', function () {
    test('increments crawls_failed when crawl fails', function () {
        Log::spy();

        $retailer = Retailer::factory()->create(['slug' => 'bm', 'name' => 'B&M']);

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
        $aggregate->markAsFailed('Connection timeout');
        $aggregate->persist();

        $statistic = CrawlStatistic::query()
            ->where('retailer_id', $retailer->id)
            ->where('date', today())
            ->first();

        expect($statistic)->not->toBeNull()
            ->and($statistic->crawls_started)->toBe(1)
            ->and($statistic->crawls_completed)->toBe(0)
            ->and($statistic->crawls_failed)->toBe(1);
    });

    test('logs warning when crawl start event not found for failure', function () {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'Could not determine retailer for failed crawl');
            });

        $projector = new CrawlStatisticsProjector;
        $event = new CrawlFailed(
            crawlId: 'non-existent-crawl-id',
            reason: 'Connection timeout',
            context: [],
        );

        $projector->onCrawlFailed($event);

        $count = CrawlStatistic::count();
        expect($count)->toBe(0);
    });
});

describe('retailer isolation', function () {
    test('tracks statistics separately per retailer', function () {
        Log::spy();

        $bmRetailer = Retailer::factory()->create(['slug' => 'bm', 'name' => 'B&M']);
        $pahRetailer = Retailer::factory()->create(['slug' => 'pets-at-home', 'name' => 'Pets at Home']);

        // B&M - 3 successful crawls
        for ($i = 0; $i < 3; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'bm');
            $aggregate->completeCrawl();
            $aggregate->persist();
        }

        // Pets at Home - 2 failed crawls
        for ($i = 0; $i < 2; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.petsathome.com/dogs', retailer: 'pets-at-home');
            $aggregate->markAsFailed('Test failure');
            $aggregate->persist();
        }

        $bmStatistic = CrawlStatistic::query()
            ->where('retailer_id', $bmRetailer->id)
            ->where('date', today())
            ->first();

        $pahStatistic = CrawlStatistic::query()
            ->where('retailer_id', $pahRetailer->id)
            ->where('date', today())
            ->first();

        expect($bmStatistic->crawls_started)->toBe(3)
            ->and($bmStatistic->crawls_completed)->toBe(3)
            ->and($bmStatistic->crawls_failed)->toBe(0)
            ->and($pahStatistic->crawls_started)->toBe(2)
            ->and($pahStatistic->crawls_completed)->toBe(0)
            ->and($pahStatistic->crawls_failed)->toBe(2);
    });
});

describe('mixed crawl outcomes', function () {
    test('tracks mixed outcomes correctly', function () {
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

        $statistic = CrawlStatistic::query()
            ->where('retailer_id', $retailer->id)
            ->where('date', today())
            ->first();

        expect($statistic->crawls_started)->toBe(5)
            ->and($statistic->crawls_completed)->toBe(3)
            ->and($statistic->crawls_failed)->toBe(2)
            ->and($statistic->success_rate)->toBe(60.0);
    });
});

describe('CrawlStatistic model', function () {
    test('calculates success_rate correctly', function () {
        $retailer = Retailer::factory()->create();

        $statistic = CrawlStatistic::factory()
            ->for($retailer)
            ->create([
                'crawls_completed' => 80,
                'crawls_failed' => 20,
            ]);

        expect($statistic->success_rate)->toBe(80.0);
    });

    test('returns null for success_rate when no crawls', function () {
        $retailer = Retailer::factory()->create();

        $statistic = CrawlStatistic::factory()
            ->for($retailer)
            ->create([
                'crawls_completed' => 0,
                'crawls_failed' => 0,
            ]);

        expect($statistic->success_rate)->toBeNull();
    });

    test('belongs to retailer', function () {
        $retailer = Retailer::factory()->create();

        $statistic = CrawlStatistic::factory()
            ->for($retailer)
            ->create();

        expect($statistic->retailer)->not->toBeNull()
            ->and($statistic->retailer->id)->toBe($retailer->id);
    });
});

describe('CrawlStatistic factory', function () {
    test('creates valid statistics', function () {
        $statistic = CrawlStatistic::factory()->create();

        expect($statistic)->not->toBeNull()
            ->and($statistic->retailer_id)->not->toBeNull()
            ->and($statistic->date)->not->toBeNull();
    });

    test('perfect state creates all successful crawls', function () {
        $statistic = CrawlStatistic::factory()->perfect()->create();

        expect($statistic->crawls_started)->toBe($statistic->crawls_completed)
            ->and($statistic->crawls_failed)->toBe(0);
    });

    test('allFailed state creates all failed crawls', function () {
        $statistic = CrawlStatistic::factory()->allFailed()->create();

        expect($statistic->crawls_started)->toBe($statistic->crawls_failed)
            ->and($statistic->crawls_completed)->toBe(0);
    });

    test('forDate sets specific date', function () {
        $date = '2026-01-15';
        $statistic = CrawlStatistic::factory()->forDate($date)->create();

        expect($statistic->date->format('Y-m-d'))->toBe($date);
    });
});
