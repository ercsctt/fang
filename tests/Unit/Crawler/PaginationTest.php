<?php

declare(strict_types=1);

use App\Crawler\DTOs\PaginatedUrl;
use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\Amazon\AmazonProductListingUrlExtractor;
use App\Crawler\Extractors\PetsAtHome\PAHProductListingUrlExtractor;

describe('PaginatedUrl DTO', function () {
    test('creates PaginatedUrl with all properties', function () {
        $paginatedUrl = new PaginatedUrl(
            url: 'https://example.com/page/2',
            retailer: 'test-retailer',
            page: 2,
            category: 'dog-food',
            discoveredFrom: 'https://example.com/page/1',
        );

        expect($paginatedUrl->url)->toBe('https://example.com/page/2')
            ->and($paginatedUrl->retailer)->toBe('test-retailer')
            ->and($paginatedUrl->page)->toBe(2)
            ->and($paginatedUrl->category)->toBe('dog-food')
            ->and($paginatedUrl->discoveredFrom)->toBe('https://example.com/page/1');
    });

    test('toArray returns correct structure', function () {
        $paginatedUrl = new PaginatedUrl(
            url: 'https://example.com/page/2',
            retailer: 'test-retailer',
            page: 2,
            category: 'dog-food',
            discoveredFrom: 'https://example.com/page/1',
        );

        $array = $paginatedUrl->toArray();

        expect($array)->toBe([
            'url' => 'https://example.com/page/2',
            'retailer' => 'test-retailer',
            'page' => 2,
            'category' => 'dog-food',
            'discovered_from' => 'https://example.com/page/1',
        ]);
    });

    test('handles nullable category and discoveredFrom', function () {
        $paginatedUrl = new PaginatedUrl(
            url: 'https://example.com/page/2',
            retailer: 'test-retailer',
            page: 2,
        );

        expect($paginatedUrl->category)->toBeNull()
            ->and($paginatedUrl->discoveredFrom)->toBeNull();
    });
});

describe('Amazon pagination extraction', function () {
    beforeEach(function () {
        $this->extractor = new AmazonProductListingUrlExtractor;
    });

    test('extracts next page URL from Amazon search results', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="s-result-list">
                <a href="/dp/B08L5WRMZJ">Product 1</a>
            </div>
            <div class="s-pagination-container">
                <a class="s-pagination-next" href="/s?k=dog+food&page=2">Next</a>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        $paginatedUrls = array_filter($results, fn ($r) => $r instanceof PaginatedUrl);
        expect($paginatedUrls)->toHaveCount(1);

        $pagination = array_values($paginatedUrls)[0];
        expect($pagination->page)->toBe(2)
            ->and($pagination->retailer)->toBe('amazon-uk')
            ->and($pagination->url)->toContain('page=2');
    });

    test('extracts next page URL from aria-label link', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/dp/B08L5WRMZJ">Product</a>
            <nav aria-label="pagination">
                <a aria-label="Go to next page" href="/s?k=dog+food&page=2">Next</a>
            </nav>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        $paginatedUrls = array_filter($results, fn ($r) => $r instanceof PaginatedUrl);
        expect($paginatedUrls)->toHaveCount(1);
    });

    test('does not yield pagination when no next page link exists', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/dp/B08L5WRMZJ">Product</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        $paginatedUrls = array_filter($results, fn ($r) => $r instanceof PaginatedUrl);
        expect($paginatedUrls)->toBeEmpty();
    });

    test('calculates correct page number from URL', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/dp/B08L5WRMZJ">Product</a>
            <a class="s-pagination-next" href="/s?k=dog+food&page=4">Next</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/s?k=dog+food&page=3';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        $paginatedUrls = array_filter($results, fn ($r) => $r instanceof PaginatedUrl);
        $pagination = array_values($paginatedUrls)[0];

        expect($pagination->page)->toBe(4);
    });

    test('normalizes relative pagination URLs to absolute', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/dp/B08L5WRMZJ">Product</a>
            <a class="s-pagination-next" href="/s?k=dog+food&page=2">Next</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        $paginatedUrls = array_filter($results, fn ($r) => $r instanceof PaginatedUrl);
        $pagination = array_values($paginatedUrls)[0];

        expect($pagination->url)->toStartWith('https://www.amazon.co.uk/');
    });
});

describe('Pets at Home pagination extraction', function () {
    beforeEach(function () {
        $this->extractor = new PAHProductListingUrlExtractor;
    });

    test('extracts next page URL using rel=next', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/product/dog-food-product/P12345">Product 1</a>
            <nav>
                <a rel="next" href="/shop/en/pets/dog/dog-food?page=2">Next</a>
            </nav>
        </body>
        </html>
        HTML;
        $url = 'https://www.petsathome.com/shop/en/pets/dog/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        $paginatedUrls = array_filter($results, fn ($r) => $r instanceof PaginatedUrl);
        expect($paginatedUrls)->toHaveCount(1);

        $pagination = array_values($paginatedUrls)[0];
        expect($pagination->retailer)->toBe('pets-at-home')
            ->and($pagination->page)->toBe(2);
    });

    test('extracts next page URL from numbered pagination', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/product/dog-food-product/P12345">Product 1</a>
            <div class="pagination">
                <a href="/shop/en/pets/dog/dog-food?page=1">1</a>
                <a href="/shop/en/pets/dog/dog-food?page=2">2</a>
                <a href="/shop/en/pets/dog/dog-food?page=3">3</a>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.petsathome.com/shop/en/pets/dog/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        $paginatedUrls = array_filter($results, fn ($r) => $r instanceof PaginatedUrl);
        expect($paginatedUrls)->toHaveCount(1);

        $pagination = array_values($paginatedUrls)[0];
        expect($pagination->page)->toBe(2);
    });

    test('skips javascript void links', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/product/dog-food-product/P12345">Product 1</a>
            <a rel="next" href="javascript:void(0)">Next</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.petsathome.com/shop/en/pets/dog/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        $paginatedUrls = array_filter($results, fn ($r) => $r instanceof PaginatedUrl);
        expect($paginatedUrls)->toBeEmpty();
    });

    test('passes category through to paginated URL', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/product/dog-food-product/P12345">Product 1</a>
            <a rel="next" href="/shop/en/pets/dog/dog-food?page=2">Next</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.petsathome.com/shop/en/pets/dog/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        $paginatedUrls = array_filter($results, fn ($r) => $r instanceof PaginatedUrl);
        $pagination = array_values($paginatedUrls)[0];

        expect($pagination->category)->toBe('dog-food');
    });
});

describe('extraction yields both products and pagination', function () {
    test('Amazon yields products and pagination in same extraction', function () {
        $extractor = new AmazonProductListingUrlExtractor;

        $html = <<<'HTML'
        <html>
        <body>
            <a href="/dp/B08L5WRMZJ">Product 1</a>
            <a href="/dp/B07N4CXL1Y">Product 2</a>
            <a class="s-pagination-next" href="/s?k=dog+food&page=2">Next</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($extractor->extract($html, $url));

        $products = array_filter($results, fn ($r) => $r instanceof ProductListingUrl);
        $pagination = array_filter($results, fn ($r) => $r instanceof PaginatedUrl);

        expect($products)->toHaveCount(2)
            ->and($pagination)->toHaveCount(1);
    });

    test('Pets at Home yields products and pagination in same extraction', function () {
        $extractor = new PAHProductListingUrlExtractor;

        $html = <<<'HTML'
        <html>
        <body>
            <a href="/product/dry-dog-food/P12345">Product 1</a>
            <a href="/product/wet-dog-food/P67890">Product 2</a>
            <a rel="next" href="/shop/en/pets/dog/dog-food?page=2">Next</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.petsathome.com/shop/en/pets/dog/dog-food';

        $results = iterator_to_array($extractor->extract($html, $url));

        $products = array_filter($results, fn ($r) => $r instanceof ProductListingUrl);
        $pagination = array_filter($results, fn ($r) => $r instanceof PaginatedUrl);

        expect($products)->toHaveCount(2)
            ->and($pagination)->toHaveCount(1);
    });
});
