<?php

declare(strict_types=1);

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\Contracts\HttpAdapterInterface;
use App\Crawler\DTOs\PaginatedUrl;
use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Scrapers\BaseCrawler;
use Illuminate\Support\Facades\Config;

/**
 * Helper function to create a Generator from an array of items.
 *
 * @param  array<mixed>  $items
 * @return Generator<mixed>
 */
function makeGenerator(array $items): Generator
{
    foreach ($items as $item) {
        yield $item;
    }
}

beforeEach(function () {
    // Set up config for tests
    Config::set('retailers.test-retailer.max_pages', 10);
    Config::set('retailers.test-retailer.request_delay_ms', 100);
    Config::set('retailers.defaults.max_pages', 5);
    Config::set('retailers.defaults.request_delay_ms', 100);
});

/**
 * Creates a test crawler with configurable behavior.
 */
function createTestCrawler(array $pages): BaseCrawler
{
    $httpAdapter = Mockery::mock(HttpAdapterInterface::class);

    foreach ($pages as $url => $html) {
        $httpAdapter->shouldReceive('fetchHtml')
            ->with($url, Mockery::any())
            ->andReturn($html);
        $httpAdapter->shouldReceive('getLastStatusCode')
            ->andReturn(200);
    }

    return new class($httpAdapter) extends BaseCrawler
    {
        public function getRetailerName(): string
        {
            return 'Test Retailer';
        }

        public function getRetailerSlug(): string
        {
            return 'test-retailer';
        }

        public function getStartingUrls(): array
        {
            return ['https://example.com/category'];
        }
    };
}

describe('BaseCrawler URL tracking', function () {
    test('tracks crawled URLs', function () {
        $crawler = createTestCrawler([
            'https://example.com/page1' => '<html></html>',
        ]);

        expect($crawler->hasBeenCrawled('https://example.com/page1'))->toBeFalse();

        $crawler->markAsCrawled('https://example.com/page1');

        expect($crawler->hasBeenCrawled('https://example.com/page1'))->toBeTrue();
    });

    test('normalizes URLs for tracking', function () {
        $crawler = createTestCrawler([]);

        $crawler->markAsCrawled('https://example.com/page?utm_source=test&a=1');

        // Same URL without tracking params should be considered crawled
        expect($crawler->hasBeenCrawled('https://example.com/page?a=1'))->toBeTrue();

        // Different query params should be different
        expect($crawler->hasBeenCrawled('https://example.com/page?a=2'))->toBeFalse();
    });

    test('resets crawled URLs', function () {
        $crawler = createTestCrawler([]);

        $crawler->markAsCrawled('https://example.com/page1');
        $crawler->markAsCrawled('https://example.com/page2');

        expect($crawler->getCrawledUrlCount())->toBe(2);

        $crawler->resetCrawledUrls();

        expect($crawler->getCrawledUrlCount())->toBe(0);
    });

    test('counts crawled URLs', function () {
        $crawler = createTestCrawler([]);

        expect($crawler->getCrawledUrlCount())->toBe(0);

        $crawler->markAsCrawled('https://example.com/page1');
        expect($crawler->getCrawledUrlCount())->toBe(1);

        $crawler->markAsCrawled('https://example.com/page2');
        expect($crawler->getCrawledUrlCount())->toBe(2);
    });
});

describe('BaseCrawler configuration', function () {
    test('gets max pages from config', function () {
        $crawler = createTestCrawler([]);

        expect($crawler->getMaxPages())->toBe(10);
    });

    test('falls back to default max pages', function () {
        Config::set('retailers.test-retailer.max_pages', null);

        $crawler = createTestCrawler([]);

        expect($crawler->getMaxPages())->toBe(5);
    });

    test('gets request delay from config', function () {
        $crawler = createTestCrawler([]);

        expect($crawler->getRequestDelay())->toBe(100);
    });

    test('generates retailer slug from name', function () {
        $httpAdapter = Mockery::mock(HttpAdapterInterface::class);

        $crawler = new class($httpAdapter) extends BaseCrawler
        {
            public function getRetailerName(): string
            {
                return 'Amazon UK';
            }

            public function getStartingUrls(): array
            {
                return [];
            }
        };

        expect($crawler->getRetailerSlug())->toBe('amazon-uk');
    });
});

describe('BaseCrawler crawlWithPagination', function () {
    test('crawls single page when no pagination', function () {
        $extractor = Mockery::mock(ExtractorInterface::class);
        $extractor->shouldReceive('canHandle')->andReturn(true);
        $extractor->shouldReceive('extract')->andReturnUsing(fn () => makeGenerator([
            new ProductListingUrl('https://example.com/product/1', 'test-retailer', 'category'),
            new ProductListingUrl('https://example.com/product/2', 'test-retailer', 'category'),
        ]));

        $httpAdapter = Mockery::mock(HttpAdapterInterface::class);
        $httpAdapter->shouldReceive('fetchHtml')
            ->once()
            ->andReturn('<html></html>');
        $httpAdapter->shouldReceive('getLastStatusCode')
            ->andReturn(200);

        $crawler = new class($httpAdapter) extends BaseCrawler
        {
            public function getRetailerName(): string
            {
                return 'Test Retailer';
            }

            public function getRetailerSlug(): string
            {
                return 'test-retailer';
            }

            public function getStartingUrls(): array
            {
                return [];
            }
        };

        $crawler->addExtractor($extractor);

        $results = iterator_to_array($crawler->crawlWithPagination('https://example.com/page1', 5));

        expect($results)->toHaveCount(2)
            ->and($results[0])->toBeInstanceOf(ProductListingUrl::class);
    });

    test('follows pagination URLs', function () {
        $httpAdapter = Mockery::mock(HttpAdapterInterface::class);
        $httpAdapter->shouldReceive('fetchHtml')
            ->with('https://example.com/page1', Mockery::any())
            ->once()
            ->andReturn('<html>page1</html>');
        $httpAdapter->shouldReceive('fetchHtml')
            ->with('https://example.com/page2', Mockery::any())
            ->once()
            ->andReturn('<html>page2</html>');
        $httpAdapter->shouldReceive('getLastStatusCode')
            ->andReturn(200);

        $extractor = Mockery::mock(ExtractorInterface::class);
        $extractor->shouldReceive('canHandle')->andReturn(true);

        $callCount = 0;
        $extractor->shouldReceive('extract')
            ->andReturnUsing(function ($html, $url) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return makeGenerator([
                        new ProductListingUrl('https://example.com/product/1', 'test-retailer'),
                        new PaginatedUrl('https://example.com/page2', 'test-retailer', 2),
                    ]);
                }

                return makeGenerator([
                    new ProductListingUrl('https://example.com/product/2', 'test-retailer'),
                ]);
            });

        $crawler = new class($httpAdapter) extends BaseCrawler
        {
            public function getRetailerName(): string
            {
                return 'Test Retailer';
            }

            public function getRetailerSlug(): string
            {
                return 'test-retailer';
            }

            public function getStartingUrls(): array
            {
                return [];
            }
        };

        $crawler->addExtractor($extractor);

        $results = iterator_to_array($crawler->crawlWithPagination('https://example.com/page1', 5));

        expect($results)->toHaveCount(2)
            ->and($results[0]->url)->toBe('https://example.com/product/1')
            ->and($results[1]->url)->toBe('https://example.com/product/2');
    });

    test('respects max pages limit', function () {
        $httpAdapter = Mockery::mock(HttpAdapterInterface::class);
        $httpAdapter->shouldReceive('fetchHtml')
            ->times(3) // Should only fetch 3 pages
            ->andReturn('<html></html>');
        $httpAdapter->shouldReceive('getLastStatusCode')
            ->andReturn(200);

        $extractor = Mockery::mock(ExtractorInterface::class);
        $extractor->shouldReceive('canHandle')->andReturn(true);

        $page = 1;
        $extractor->shouldReceive('extract')
            ->andReturnUsing(function () use (&$page) {
                $currentPage = $page++;

                return makeGenerator([
                    new ProductListingUrl("https://example.com/product/{$currentPage}", 'test-retailer'),
                    new PaginatedUrl("https://example.com/page/{$page}", 'test-retailer', $page),
                ]);
            });

        $crawler = new class($httpAdapter) extends BaseCrawler
        {
            public function getRetailerName(): string
            {
                return 'Test Retailer';
            }

            public function getRetailerSlug(): string
            {
                return 'test-retailer';
            }

            public function getStartingUrls(): array
            {
                return [];
            }
        };

        $crawler->addExtractor($extractor);

        $results = iterator_to_array($crawler->crawlWithPagination('https://example.com/page/1', 3));

        expect($results)->toHaveCount(3);
    });

    test('skips duplicate URLs', function () {
        $httpAdapter = Mockery::mock(HttpAdapterInterface::class);
        $httpAdapter->shouldReceive('fetchHtml')
            ->once() // Should only fetch once since page 2 is duplicate
            ->andReturn('<html></html>');
        $httpAdapter->shouldReceive('getLastStatusCode')
            ->andReturn(200);

        $extractor = Mockery::mock(ExtractorInterface::class);
        $extractor->shouldReceive('canHandle')->andReturn(true);
        $extractor->shouldReceive('extract')
            ->andReturnUsing(fn () => makeGenerator([
                new ProductListingUrl('https://example.com/product/1', 'test-retailer'),
                new PaginatedUrl('https://example.com/page1', 'test-retailer', 2), // Same as start URL
            ]));

        $crawler = new class($httpAdapter) extends BaseCrawler
        {
            public function getRetailerName(): string
            {
                return 'Test Retailer';
            }

            public function getRetailerSlug(): string
            {
                return 'test-retailer';
            }

            public function getStartingUrls(): array
            {
                return [];
            }
        };

        $crawler->addExtractor($extractor);

        $results = iterator_to_array($crawler->crawlWithPagination('https://example.com/page1', 5));

        expect($results)->toHaveCount(1);
    });
});
