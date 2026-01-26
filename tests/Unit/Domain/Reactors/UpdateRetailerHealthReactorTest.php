<?php

declare(strict_types=1);

use App\Domain\Crawler\Aggregates\CrawlAggregate;
use App\Domain\Crawler\Events\CrawlCompleted;
use App\Domain\Crawler\Reactors\UpdateRetailerHealthReactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
});

describe('successful crawl tracking', function () {
    test('records successful crawl result', function () {
        Log::spy();

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
        $aggregate->completeCrawl(['duration_seconds' => 5.5]);
        $aggregate->persist();

        $metrics = UpdateRetailerHealthReactor::getHealthMetrics('B&M');

        expect($metrics)->not->toBeNull()
            ->and($metrics['success_rate'])->toBe(100.0)
            ->and($metrics['total_crawls'])->toBe(1)
            ->and($metrics['successful_crawls'])->toBe(1)
            ->and($metrics['failed_crawls'])->toBe(0);
    });

    test('tracks average duration correctly', function () {
        Log::spy();

        // First crawl - 5 seconds
        $crawlId1 = Str::uuid()->toString();
        $aggregate1 = CrawlAggregate::retrieve($crawlId1);
        $aggregate1->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
        $aggregate1->completeCrawl(['duration_seconds' => 5.0]);
        $aggregate1->persist();

        // Second crawl - 10 seconds
        $crawlId2 = Str::uuid()->toString();
        $aggregate2 = CrawlAggregate::retrieve($crawlId2);
        $aggregate2->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
        $aggregate2->completeCrawl(['duration_seconds' => 10.0]);
        $aggregate2->persist();

        $metrics = UpdateRetailerHealthReactor::getHealthMetrics('B&M');

        expect($metrics['avg_duration_seconds'])->toBe(7.5);
    });

    test('updates last successful crawl timestamp', function () {
        Log::spy();

        $beforeCrawl = now()->timestamp;

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
        $aggregate->completeCrawl();
        $aggregate->persist();

        $metrics = UpdateRetailerHealthReactor::getHealthMetrics('B&M');

        expect($metrics['last_successful_crawl'])->toBeGreaterThanOrEqual($beforeCrawl);
    });
});

describe('failed crawl tracking', function () {
    test('records failed crawl result', function () {
        Log::spy();

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
        $aggregate->markAsFailed('Test failure');
        $aggregate->persist();

        $metrics = UpdateRetailerHealthReactor::getHealthMetrics('B&M');

        expect($metrics)->not->toBeNull()
            ->and($metrics['success_rate'])->toBe(0.0)
            ->and($metrics['total_crawls'])->toBe(1)
            ->and($metrics['successful_crawls'])->toBe(0)
            ->and($metrics['failed_crawls'])->toBe(1);
    });

    test('does not update last successful crawl on failure', function () {
        Log::spy();

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
        $aggregate->markAsFailed('Test failure');
        $aggregate->persist();

        $metrics = UpdateRetailerHealthReactor::getHealthMetrics('B&M');

        expect($metrics['last_successful_crawl'])->toBeNull();
    });
});

describe('mixed crawl outcomes', function () {
    test('calculates success rate correctly with mixed outcomes', function () {
        Log::spy();

        // 3 successful crawls
        for ($i = 0; $i < 3; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
            $aggregate->completeCrawl();
            $aggregate->persist();
        }

        // 2 failed crawls
        for ($i = 0; $i < 2; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
            $aggregate->markAsFailed('Test failure');
            $aggregate->persist();
        }

        $metrics = UpdateRetailerHealthReactor::getHealthMetrics('B&M');

        expect($metrics['success_rate'])->toBe(60.0)
            ->and($metrics['total_crawls'])->toBe(5)
            ->and($metrics['successful_crawls'])->toBe(3)
            ->and($metrics['failed_crawls'])->toBe(2);
    });
});

describe('retailer isolation', function () {
    test('tracks metrics separately per retailer', function () {
        Log::spy();

        // B&M - all successful
        for ($i = 0; $i < 3; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
            $aggregate->completeCrawl();
            $aggregate->persist();
        }

        // Pets at Home - all failed
        for ($i = 0; $i < 2; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.petsathome.com/dogs', retailer: 'Pets at Home');
            $aggregate->markAsFailed('Test failure');
            $aggregate->persist();
        }

        $bmMetrics = UpdateRetailerHealthReactor::getHealthMetrics('B&M');
        $pahMetrics = UpdateRetailerHealthReactor::getHealthMetrics('Pets at Home');

        expect($bmMetrics['success_rate'])->toBe(100.0)
            ->and($bmMetrics['total_crawls'])->toBe(3)
            ->and($pahMetrics['success_rate'])->toBe(0.0)
            ->and($pahMetrics['total_crawls'])->toBe(2);
    });
});

describe('edge cases', function () {
    test('handles missing retailer gracefully', function () {
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

    test('returns null for unknown retailer', function () {
        expect(UpdateRetailerHealthReactor::getHealthMetrics('Unknown Retailer'))->toBeNull();
    });
});
