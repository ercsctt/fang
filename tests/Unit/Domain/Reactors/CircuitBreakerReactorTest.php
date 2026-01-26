<?php

declare(strict_types=1);

use App\Domain\Crawler\Aggregates\CrawlAggregate;
use App\Domain\Crawler\Reactors\CircuitBreakerReactor;
use App\Models\Retailer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
});

describe('failure tracking', function () {
    test('tracks crawl outcomes in cache', function () {
        Log::spy();

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
        $aggregate->markAsFailed('Test failure');
        $aggregate->persist();

        $outcomes = Cache::get('crawler:circuit:tracking:B&M');

        expect($outcomes)->toHaveCount(1)
            ->and($outcomes[0]['success'])->toBeFalse();
    });

    test('tracks successful outcomes', function () {
        Log::spy();

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
        $aggregate->completeCrawl();
        $aggregate->persist();

        $outcomes = Cache::get('crawler:circuit:tracking:B&M');

        expect($outcomes)->toHaveCount(1)
            ->and($outcomes[0]['success'])->toBeTrue();
    });
});

describe('circuit breaker activation', function () {
    test('activates circuit breaker when failure rate exceeds threshold', function () {
        Log::spy();

        // Create retailer (slug must match Str::slug('B&M') = 'bm')
        $retailer = Retailer::factory()->create([
            'slug' => 'bm',
            'name' => 'B&M',
            'is_active' => true,
        ]);

        expect($retailer->is_active)->toBeTrue();

        // 3 failures (100% failure rate)
        for ($i = 0; $i < 3; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
            $aggregate->markAsFailed("Failure {$i}");
            $aggregate->persist();
        }

        $retailer->refresh();
        expect($retailer->is_active)->toBeFalse()
            ->and(CircuitBreakerReactor::isOpen('B&M'))->toBeTrue();
    });

    test('does not activate circuit breaker below minimum crawls', function () {
        Log::spy();

        // Create retailer (slug must match Str::slug('B&M') = 'bm')
        $retailer = Retailer::factory()->create([
            'slug' => 'bm',
            'name' => 'B&M',
            'is_active' => true,
        ]);

        // Only 2 failures (below minimum of 3)
        for ($i = 0; $i < 2; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
            $aggregate->markAsFailed("Failure {$i}");
            $aggregate->persist();
        }

        $retailer->refresh();
        expect($retailer->is_active)->toBeTrue()
            ->and(CircuitBreakerReactor::isOpen('B&M'))->toBeFalse();
    });

    test('does not activate circuit breaker when failure rate is below threshold', function () {
        Log::spy();

        // Create retailer
        $retailer = Retailer::factory()->create([
            'slug' => 'bm',
            'name' => 'B&M',
            'is_active' => true,
        ]);

        // 2 successes, 1 failure (33% failure rate, below 50% threshold)
        for ($i = 0; $i < 2; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
            $aggregate->completeCrawl();
            $aggregate->persist();
        }

        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
        $aggregate->markAsFailed('Single failure');
        $aggregate->persist();

        $retailer->refresh();
        expect($retailer->is_active)->toBeTrue()
            ->and(CircuitBreakerReactor::isOpen('B&M'))->toBeFalse();
    });

    test('activates at exactly 50% failure rate', function () {
        Log::spy();

        // Create retailer
        $retailer = Retailer::factory()->create([
            'slug' => 'bm',
            'name' => 'B&M',
            'is_active' => true,
        ]);

        // 2 successes, 2 failures (50% failure rate, equals threshold)
        for ($i = 0; $i < 2; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
            $aggregate->completeCrawl();
            $aggregate->persist();
        }

        for ($i = 0; $i < 2; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
            $aggregate->markAsFailed("Failure {$i}");
            $aggregate->persist();
        }

        $retailer->refresh();
        expect($retailer->is_active)->toBeFalse()
            ->and(CircuitBreakerReactor::isOpen('B&M'))->toBeTrue();
    });
});

describe('circuit breaker reset', function () {
    test('resets circuit breaker on successful crawl after opening', function () {
        Log::spy();

        // Create retailer
        $retailer = Retailer::factory()->create([
            'slug' => 'bm',
            'name' => 'B&M',
            'is_active' => true,
        ]);

        // Trigger circuit breaker
        for ($i = 0; $i < 3; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
            $aggregate->markAsFailed("Failure {$i}");
            $aggregate->persist();
        }

        expect(CircuitBreakerReactor::isOpen('B&M'))->toBeTrue();

        // Successful crawl should reset the circuit
        $crawlId = Str::uuid()->toString();
        $aggregate = CrawlAggregate::retrieve($crawlId);
        $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
        $aggregate->completeCrawl();
        $aggregate->persist();

        expect(CircuitBreakerReactor::isOpen('B&M'))->toBeFalse();
    });

    test('manual reset reactivates retailer', function () {
        Log::spy();

        // Create retailer
        $retailer = Retailer::factory()->create([
            'slug' => 'bm',
            'name' => 'B&M',
            'is_active' => false,
        ]);

        // Set circuit as open
        Cache::put('crawler:circuit:open:B&M', true, now()->addMinutes(30));

        CircuitBreakerReactor::reset('B&M');

        $retailer->refresh();
        expect($retailer->is_active)->toBeTrue()
            ->and(CircuitBreakerReactor::isOpen('B&M'))->toBeFalse();
    });
});

describe('retailer isolation', function () {
    test('circuit breaker activates per retailer', function () {
        Log::spy();

        // Create retailers
        $bmRetailer = Retailer::factory()->create([
            'slug' => 'bm',
            'name' => 'B&M',
            'is_active' => true,
        ]);

        $pahRetailer = Retailer::factory()->create([
            'slug' => 'pets-at-home',
            'name' => 'Pets at Home',
            'is_active' => true,
        ]);

        // B&M failures
        for ($i = 0; $i < 3; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.bmstores.co.uk/pets', retailer: 'B&M');
            $aggregate->markAsFailed("B&M Failure {$i}");
            $aggregate->persist();
        }

        // Pets at Home successes
        for ($i = 0; $i < 3; $i++) {
            $crawlId = Str::uuid()->toString();
            $aggregate = CrawlAggregate::retrieve($crawlId);
            $aggregate->startCrawl(url: 'https://www.petsathome.com/dogs', retailer: 'Pets at Home');
            $aggregate->completeCrawl();
            $aggregate->persist();
        }

        $bmRetailer->refresh();
        $pahRetailer->refresh();

        expect($bmRetailer->is_active)->toBeFalse()
            ->and(CircuitBreakerReactor::isOpen('B&M'))->toBeTrue()
            ->and($pahRetailer->is_active)->toBeTrue()
            ->and(CircuitBreakerReactor::isOpen('Pets at Home'))->toBeFalse();
    });
});

describe('static helper methods', function () {
    test('isOpen returns correct state', function () {
        expect(CircuitBreakerReactor::isOpen('Unknown'))->toBeFalse();

        Cache::put('crawler:circuit:open:Test', true, now()->addMinutes(30));

        expect(CircuitBreakerReactor::isOpen('Test'))->toBeTrue();
    });

    test('getCooldownExpiry returns correct timestamp', function () {
        expect(CircuitBreakerReactor::getCooldownExpiry('Unknown'))->toBeNull();

        $timestamp = now()->timestamp;
        Cache::put('crawler:circuit:cooldown:Test', $timestamp, now()->addMinutes(30));

        expect(CircuitBreakerReactor::getCooldownExpiry('Test'))->toBe($timestamp);
    });
});
