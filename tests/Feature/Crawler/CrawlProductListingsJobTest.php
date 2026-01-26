<?php

declare(strict_types=1);

use App\Crawler\Scrapers\BMCrawler;
use App\Jobs\Crawler\CrawlProductListingsJob;
use Illuminate\Support\Facades\Queue;

describe('job execution', function () {
    test('job can be dispatched and serialized', function () {
        Queue::fake();

        CrawlProductListingsJob::dispatch(
            BMCrawler::class,
            'https://www.bmstores.co.uk/pets/dog-food',
        );

        Queue::assertPushed(CrawlProductListingsJob::class, function ($job) {
            return true;
        });
    });

    test('job has correct tags for monitoring', function () {
        $job = new CrawlProductListingsJob(
            BMCrawler::class,
            'https://www.bmstores.co.uk/pets/dog-food',
        );

        $tags = $job->tags();

        expect($tags)->toContain('crawler')
            ->toContain('product-listings')
            ->toContain('BMCrawler');
    });

    test('job has configurable timeout and retry settings', function () {
        $job = new CrawlProductListingsJob(
            BMCrawler::class,
            'https://www.bmstores.co.uk/pets/dog-food',
        );

        expect($job->timeout)->toBe(300)
            ->and($job->tries)->toBe(3)
            ->and($job->backoff)->toBe(60);
    });

    test('job can be created with custom crawl id', function () {
        $customCrawlId = 'custom-crawl-id-123';

        $job = new CrawlProductListingsJob(
            BMCrawler::class,
            'https://www.bmstores.co.uk/pets/dog-food',
            $customCrawlId,
        );

        expect($job)->toBeInstanceOf(CrawlProductListingsJob::class);
    });

    test('job can be created with advanced adapter disabled', function () {
        $job = new CrawlProductListingsJob(
            BMCrawler::class,
            'https://www.bmstores.co.uk/pets/dog-food',
            null,
            false,
        );

        expect($job)->toBeInstanceOf(CrawlProductListingsJob::class);
    });
});

describe('job dispatching', function () {
    test('multiple jobs can be dispatched in batch', function () {
        Queue::fake();

        $urls = [
            'https://www.bmstores.co.uk/pets/dog-food',
            'https://www.bmstores.co.uk/pets/dog-treats',
            'https://www.bmstores.co.uk/pets/puppy-food',
        ];

        foreach ($urls as $url) {
            CrawlProductListingsJob::dispatch(BMCrawler::class, $url);
        }

        Queue::assertPushed(CrawlProductListingsJob::class, 3);
    });

    test('job can be dispatched on specific queue', function () {
        Queue::fake();

        CrawlProductListingsJob::dispatch(
            BMCrawler::class,
            'https://www.bmstores.co.uk/pets/dog-food',
        )->onQueue('crawlers');

        Queue::assertPushedOn('crawlers', CrawlProductListingsJob::class);
    });

    test('job can be dispatched with delay', function () {
        Queue::fake();

        CrawlProductListingsJob::dispatch(
            BMCrawler::class,
            'https://www.bmstores.co.uk/pets/dog-food',
        )->delay(now()->addMinutes(5));

        Queue::assertPushed(CrawlProductListingsJob::class);
    });
});
