<?php

declare(strict_types=1);

use App\Domain\Crawler\Aggregates\CrawlAggregate;
use App\Domain\Crawler\Events\CrawlCompleted;
use App\Domain\Crawler\Events\CrawlFailed;
use App\Domain\Crawler\Events\CrawlStarted;
use App\Domain\Crawler\Events\ProductListingDiscovered;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->crawlId = Str::uuid()->toString();
    $this->aggregate = CrawlAggregate::retrieve($this->crawlId);
});

describe('startCrawl', function () {
    test('records CrawlStarted event', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets/dog-food',
            retailer: 'B&M',
            metadata: ['test' => true],
        );

        $events = $this->aggregate->getRecordedEvents();

        expect($events)->toHaveCount(1)
            ->and($events[0])->toBeInstanceOf(CrawlStarted::class)
            ->and($events[0]->crawlId)->toBe($this->crawlId)
            ->and($events[0]->url)->toBe('https://www.bmstores.co.uk/pets/dog-food')
            ->and($events[0]->retailer)->toBe('B&M')
            ->and($events[0]->metadata)->toBe(['test' => true]);
    });

    test('returns self for fluent interface', function () {
        $result = $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        expect($result)->toBe($this->aggregate);
    });
});

describe('recordProductListingDiscovered', function () {
    test('records ProductListingDiscovered event', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->recordProductListingDiscovered(
            url: 'https://www.bmstores.co.uk/product/test-123',
            retailer: 'bm',
            category: 'dog',
            metadata: ['discovered_from' => 'https://www.bmstores.co.uk/pets'],
        );

        $events = $this->aggregate->getRecordedEvents();

        expect($events)->toHaveCount(2)
            ->and($events[1])->toBeInstanceOf(ProductListingDiscovered::class)
            ->and($events[1]->crawlId)->toBe($this->crawlId)
            ->and($events[1]->url)->toBe('https://www.bmstores.co.uk/product/test-123')
            ->and($events[1]->retailer)->toBe('bm')
            ->and($events[1]->category)->toBe('dog')
            ->and($events[1]->metadata)->toBe(['discovered_from' => 'https://www.bmstores.co.uk/pets']);
    });

    test('increments product listings discovered count', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->recordProductListingDiscovered(
            url: 'https://www.bmstores.co.uk/product/test-1',
            retailer: 'bm',
        );

        $this->aggregate->recordProductListingDiscovered(
            url: 'https://www.bmstores.co.uk/product/test-2',
            retailer: 'bm',
        );

        $this->aggregate->recordProductListingDiscovered(
            url: 'https://www.bmstores.co.uk/product/test-3',
            retailer: 'bm',
        );

        $this->aggregate->completeCrawl();

        $events = $this->aggregate->getRecordedEvents();
        $completedEvent = collect($events)->first(fn ($e) => $e instanceof CrawlCompleted);

        expect($completedEvent->productListingsDiscovered)->toBe(3);
    });

    test('throws exception when crawl has failed', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->markAsFailed('Test failure');

        expect(fn () => $this->aggregate->recordProductListingDiscovered(
            url: 'https://www.bmstores.co.uk/product/test-123',
            retailer: 'bm',
        ))->toThrow(\Exception::class, 'Cannot record product listing for failed crawl');
    });

    test('handles null category', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->recordProductListingDiscovered(
            url: 'https://www.bmstores.co.uk/product/test-123',
            retailer: 'bm',
            category: null,
        );

        $events = $this->aggregate->getRecordedEvents();

        expect($events[1]->category)->toBeNull();
    });

    test('returns self for fluent interface', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $result = $this->aggregate->recordProductListingDiscovered(
            url: 'https://www.bmstores.co.uk/product/test-123',
            retailer: 'bm',
        );

        expect($result)->toBe($this->aggregate);
    });
});

describe('completeCrawl', function () {
    test('records CrawlCompleted event', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->completeCrawl([
            'duration_seconds' => 5.5,
            'discovered_count' => 10,
        ]);

        $events = $this->aggregate->getRecordedEvents();

        expect($events)->toHaveCount(2)
            ->and($events[1])->toBeInstanceOf(CrawlCompleted::class)
            ->and($events[1]->crawlId)->toBe($this->crawlId)
            ->and($events[1]->statistics)->toBe([
                'duration_seconds' => 5.5,
                'discovered_count' => 10,
            ]);
    });

    test('does not record duplicate completion', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->completeCrawl();
        $this->aggregate->completeCrawl();
        $this->aggregate->completeCrawl();

        $events = $this->aggregate->getRecordedEvents();
        $completedEvents = collect($events)->filter(fn ($e) => $e instanceof CrawlCompleted);

        expect($completedEvents)->toHaveCount(1);
    });

    test('throws exception when crawl has failed', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->markAsFailed('Test failure');

        expect(fn () => $this->aggregate->completeCrawl())
            ->toThrow(\Exception::class, 'Cannot complete a failed crawl');
    });

    test('returns self for fluent interface', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $result = $this->aggregate->completeCrawl();

        expect($result)->toBe($this->aggregate);
    });
});

describe('markAsFailed', function () {
    test('records CrawlFailed event', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->markAsFailed(
            reason: 'HTTP 500 error',
            context: ['url' => 'https://www.bmstores.co.uk/pets', 'status_code' => 500],
        );

        $events = $this->aggregate->getRecordedEvents();

        expect($events)->toHaveCount(2)
            ->and($events[1])->toBeInstanceOf(CrawlFailed::class)
            ->and($events[1]->crawlId)->toBe($this->crawlId)
            ->and($events[1]->reason)->toBe('HTTP 500 error')
            ->and($events[1]->context)->toBe(['url' => 'https://www.bmstores.co.uk/pets', 'status_code' => 500]);
    });

    test('does not record duplicate failure', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->markAsFailed('First failure');
        $this->aggregate->markAsFailed('Second failure');
        $this->aggregate->markAsFailed('Third failure');

        $events = $this->aggregate->getRecordedEvents();
        $failedEvents = collect($events)->filter(fn ($e) => $e instanceof CrawlFailed);

        expect($failedEvents)->toHaveCount(1)
            ->and($failedEvents->first()->reason)->toBe('First failure');
    });

    test('returns self for fluent interface', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $result = $this->aggregate->markAsFailed('Test failure');

        expect($result)->toBe($this->aggregate);
    });
});

describe('state transitions', function () {
    test('normal flow: start -> discover -> complete', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->recordProductListingDiscovered(
            url: 'https://www.bmstores.co.uk/product/test-1',
            retailer: 'bm',
        );

        $this->aggregate->recordProductListingDiscovered(
            url: 'https://www.bmstores.co.uk/product/test-2',
            retailer: 'bm',
        );

        $this->aggregate->completeCrawl(['duration' => 5]);

        $events = $this->aggregate->getRecordedEvents();

        expect($events)->toHaveCount(4)
            ->and($events[0])->toBeInstanceOf(CrawlStarted::class)
            ->and($events[1])->toBeInstanceOf(ProductListingDiscovered::class)
            ->and($events[2])->toBeInstanceOf(ProductListingDiscovered::class)
            ->and($events[3])->toBeInstanceOf(CrawlCompleted::class);
    });

    test('failure flow: start -> fail', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->markAsFailed('Network error');

        $events = $this->aggregate->getRecordedEvents();

        expect($events)->toHaveCount(2)
            ->and($events[0])->toBeInstanceOf(CrawlStarted::class)
            ->and($events[1])->toBeInstanceOf(CrawlFailed::class);
    });

    test('partial failure flow: start -> discover -> fail', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->recordProductListingDiscovered(
            url: 'https://www.bmstores.co.uk/product/test-1',
            retailer: 'bm',
        );

        $this->aggregate->markAsFailed('Connection timeout after partial crawl');

        $events = $this->aggregate->getRecordedEvents();

        expect($events)->toHaveCount(3)
            ->and($events[0])->toBeInstanceOf(CrawlStarted::class)
            ->and($events[1])->toBeInstanceOf(ProductListingDiscovered::class)
            ->and($events[2])->toBeInstanceOf(CrawlFailed::class);
    });
});

describe('business rules', function () {
    test('cannot complete a crawl that has already failed', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->markAsFailed('Error');

        expect(fn () => $this->aggregate->completeCrawl())
            ->toThrow(\Exception::class, 'Cannot complete a failed crawl');
    });

    test('cannot record products after crawl failed', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->markAsFailed('Error');

        expect(fn () => $this->aggregate->recordProductListingDiscovered(
            url: 'https://www.bmstores.co.uk/product/test-123',
            retailer: 'bm',
        ))->toThrow(\Exception::class, 'Cannot record product listing for failed crawl');
    });

    test('idempotent completion - second call is no-op', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->completeCrawl(['first' => true]);
        $this->aggregate->completeCrawl(['second' => true]); // Should be ignored

        $events = $this->aggregate->getRecordedEvents();
        $completedEvent = collect($events)->first(fn ($e) => $e instanceof CrawlCompleted);

        expect($completedEvent->statistics)->toBe(['first' => true]);
    });

    test('idempotent failure - second call is no-op', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->markAsFailed('First error');
        $this->aggregate->markAsFailed('Second error'); // Should be ignored

        $events = $this->aggregate->getRecordedEvents();
        $failedEvent = collect($events)->first(fn ($e) => $e instanceof CrawlFailed);

        expect($failedEvent->reason)->toBe('First error');
    });
});

describe('event data integrity', function () {
    test('CrawlStarted event contains all required fields', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
            metadata: ['key' => 'value'],
        );

        $event = $this->aggregate->getRecordedEvents()[0];

        expect($event->crawlId)->not->toBeEmpty()
            ->and($event->url)->not->toBeEmpty()
            ->and($event->retailer)->not->toBeEmpty()
            ->and($event->metadata)->toBeArray();
    });

    test('ProductListingDiscovered event contains all required fields', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->recordProductListingDiscovered(
            url: 'https://www.bmstores.co.uk/product/test-123',
            retailer: 'bm',
            category: 'dog',
            metadata: ['source' => 'test'],
        );

        $event = $this->aggregate->getRecordedEvents()[1];

        expect($event->crawlId)->not->toBeEmpty()
            ->and($event->url)->not->toBeEmpty()
            ->and($event->retailer)->not->toBeEmpty()
            ->and($event->category)->toBe('dog')
            ->and($event->metadata)->toBeArray();
    });

    test('CrawlCompleted event contains product count', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        for ($i = 0; $i < 5; $i++) {
            $this->aggregate->recordProductListingDiscovered(
                url: "https://www.bmstores.co.uk/product/test-{$i}",
                retailer: 'bm',
            );
        }

        $this->aggregate->completeCrawl();

        $event = collect($this->aggregate->getRecordedEvents())
            ->first(fn ($e) => $e instanceof CrawlCompleted);

        expect($event->productListingsDiscovered)->toBe(5);
    });

    test('CrawlFailed event contains reason and context', function () {
        $this->aggregate->startCrawl(
            url: 'https://www.bmstores.co.uk/pets',
            retailer: 'B&M',
        );

        $this->aggregate->markAsFailed(
            reason: 'Connection refused',
            context: ['attempt' => 3, 'error_code' => 'ECONNREFUSED'],
        );

        $event = collect($this->aggregate->getRecordedEvents())
            ->first(fn ($e) => $e instanceof CrawlFailed);

        expect($event->reason)->toBe('Connection refused')
            ->and($event->context['attempt'])->toBe(3)
            ->and($event->context['error_code'])->toBe('ECONNREFUSED');
    });
});
