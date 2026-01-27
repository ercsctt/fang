<?php

declare(strict_types=1);

use App\Domain\Crawler\Aggregates\CrawlAggregate;
use App\Domain\Crawler\Events\CrawlFailed;
use App\Domain\Crawler\Reactors\NotifyCrawlFailureReactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
});

describe('failure tracking', function () {
    test('increments failure count on CrawlFailed event', function () {
        $crawlId = Str::uuid()->toString();

        // Create and persist the crawl start event first
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );
        $aggregate->markAsFailed('Test failure');
        $aggregate->persist();

        $cacheKey = 'crawler:failures:B&M';
        expect(Cache::get($cacheKey))->toBe(1);
    });

    test('tracks consecutive failures correctly', function () {
        // First failure
        $crawlId1 = Str::uuid()->toString();
        $aggregate1 = CrawlAggregate::retrieve($crawlId1);
        $aggregate1->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
        $aggregate1->markAsFailed('First failure');
        $aggregate1->persist();

        // Second failure
        $crawlId2 = Str::uuid()->toString();
        $aggregate2 = CrawlAggregate::retrieve($crawlId2);
        $aggregate2->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
        $aggregate2->markAsFailed('Second failure');
        $aggregate2->persist();

        $cacheKey = 'crawler:failures:B&M';
        expect(Cache::get($cacheKey))->toBe(2);
    });

    test('tracks failures separately per retailer', function () {
        // B&M failure
        $crawlId1 = Str::uuid()->toString();
        $aggregate1 = CrawlAggregate::retrieve($crawlId1);
        $aggregate1->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
        $aggregate1->markAsFailed('B&M failure');
        $aggregate1->persist();

        // Pets at Home failure
        $crawlId2 = Str::uuid()->toString();
        $aggregate2 = CrawlAggregate::retrieve($crawlId2);
        $aggregate2->startCrawl(url: 'https://www.petsathome.com/dogs', retailer: 'Pets at Home');
        $aggregate2->markAsFailed('PAH failure');
        $aggregate2->persist();

        expect(Cache::get('crawler:failures:B&M'))->toBe(1)
            ->and(Cache::get('crawler:failures:Pets at Home'))->toBe(1);
    });
});

describe('notification triggering', function () {
    test('sends notification after threshold failures', function () {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'ALERT: Multiple consecutive crawl failures detected'
                    && $context['failure_count'] === 3
                    && $context['retailer'] === 'B&M';
            });

        // Create 3 consecutive failures
        for ($i = 1; $i <= 3; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
            $aggregate->markAsFailed("Failure {$i}");
            $aggregate->persist();
        }
    });

    test('does not send notification before threshold', function () {
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->never();

        // Only 2 failures - below threshold
        for ($i = 1; $i <= 2; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
            $aggregate->markAsFailed("Failure {$i}");
            $aggregate->persist();
        }
    });
});

describe('reset functionality', function () {
    test('can reset failure count for retailer', function () {
        // Create a failure
        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
        $aggregate->markAsFailed('Test failure');
        $aggregate->persist();

        expect(Cache::get('crawler:failures:B&M'))->toBe(1);

        // Reset
        $reactor = new NotifyCrawlFailureReactor;
        $reactor->resetFailureCount('B&M');

        expect(Cache::get('crawler:failures:B&M'))->toBeNull();
    });
});

describe('edge cases', function () {
    test('handles missing retailer gracefully', function () {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'NotifyCrawlFailureReactor: Could not determine retailer for crawl';
            });

        // Directly invoke the reactor with an event that has no CrawlStarted
        $reactor = new NotifyCrawlFailureReactor;
        $event = new CrawlFailed(
            crawlId: 'non-existent-crawl-id',
            reason: 'Test failure',
            context: [],
        );

        $reactor->onCrawlFailed($event);

        // Should not increment any cache
        expect(Cache::get('crawler:failures:B&M'))->toBeNull();
    });
});
