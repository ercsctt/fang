<?php

declare(strict_types=1);

use App\Crawler\DTOs\PaginatedUrl;
use App\Crawler\DTOs\ProductListingUrl;

describe('loadFixture', function () {
    test('loads fixture from tests/Fixtures directory', function () {
        $content = $this->loadFixture('bm-product-page.html');

        expect($content)->toBeString()
            ->and($content)->toContain('<html');
    });

    test('throws exception for non-existent fixture', function () {
        $this->loadFixture('non-existent-fixture.html');
    })->throws(RuntimeException::class, 'Fixture file not found');
});

describe('filterProductListingUrls', function () {
    test('filters array to only ProductListingUrl instances', function () {
        $productUrl = new ProductListingUrl(
            url: 'https://example.com/product/123',
            retailer: 'test',
            category: null,
            metadata: []
        );
        $paginatedUrl = new PaginatedUrl(
            url: 'https://example.com/page/2',
            retailer: 'test',
            page: 2
        );

        $results = [$productUrl, $paginatedUrl, 'string', 123];
        $filtered = $this->filterProductListingUrls($results);

        expect($filtered)->toHaveCount(1)
            ->and($filtered[0])->toBeInstanceOf(ProductListingUrl::class);
    });
});

describe('filterPaginatedUrls', function () {
    test('filters array to only PaginatedUrl instances', function () {
        $productUrl = new ProductListingUrl(
            url: 'https://example.com/product/123',
            retailer: 'test',
            category: null,
            metadata: []
        );
        $paginatedUrl = new PaginatedUrl(
            url: 'https://example.com/page/2',
            retailer: 'test',
            page: 2
        );

        $results = [$productUrl, $paginatedUrl];
        $filtered = $this->filterPaginatedUrls($results);

        expect($filtered)->toHaveCount(1)
            ->and($filtered[0])->toBeInstanceOf(PaginatedUrl::class);
    });
});

describe('html helper methods', function () {
    test('minimalHtml generates basic HTML structure', function () {
        $html = $this->minimalHtml('<p>Test</p>');

        expect($html)->toBe('<html><body><p>Test</p></body></html>');
    });

    test('emptyHtml generates empty HTML document', function () {
        $html = $this->emptyHtml();

        expect($html)->toBe('<html><body></body></html>');
    });

    test('htmlWithLink generates HTML with anchor tag', function () {
        $html = $this->htmlWithLink('/product/123', 'My Product');

        expect($html)->toContain('<a href="/product/123">My Product</a>');
    });

    test('htmlWithLinks generates HTML with multiple anchor tags', function () {
        $links = [
            ['href' => '/product/1'],
            ['href' => '/product/2', 'text' => 'Product 2'],
        ];
        $html = $this->htmlWithLinks($links);

        expect($html)->toContain('<a href="/product/1">Product</a>')
            ->and($html)->toContain('<a href="/product/2">Product 2</a>');
    });

    test('htmlWithJsonLd generates HTML with structured data', function () {
        $data = [
            '@type' => 'Product',
            'name' => 'Test Product',
        ];
        $html = $this->htmlWithJsonLd($data);

        expect($html)->toContain('application/ld+json')
            ->and($html)->toContain('"@type": "Product"')
            ->and($html)->toContain('"name": "Test Product"');
    });
});

describe('JSON-LD helper methods', function () {
    test('productJsonLd generates basic Product structure', function () {
        $jsonLd = $this->productJsonLd();

        expect($jsonLd['@type'])->toBe('Product')
            ->and($jsonLd['name'])->toBe('Test Product')
            ->and($jsonLd['offers'])->toBeArray();
    });

    test('productJsonLd allows overrides', function () {
        $jsonLd = $this->productJsonLd([
            'name' => 'Custom Product',
            'brand' => 'Test Brand',
        ]);

        expect($jsonLd['name'])->toBe('Custom Product')
            ->and($jsonLd['brand'])->toBe('Test Brand');
    });

    test('productJsonLdWithReviews generates Product with reviews', function () {
        $reviews = [
            ['reviewBody' => 'Great product!'],
        ];
        $jsonLd = $this->productJsonLdWithReviews($reviews);

        expect($jsonLd['review'])->toHaveCount(1)
            ->and($jsonLd['review'][0]['reviewBody'])->toBe('Great product!');
    });

    test('reviewJsonLd generates basic Review structure', function () {
        $review = $this->reviewJsonLd();

        expect($review['@type'])->toBe('Review')
            ->and($review['reviewRating']['ratingValue'])->toBe('5')
            ->and($review['author'])->toBe('Test User')
            ->and($review['reviewBody'])->toBe('Test review body');
    });

    test('reviewJsonLd allows overrides', function () {
        $review = $this->reviewJsonLd([
            'author' => 'Custom Author',
            'reviewRating' => ['ratingValue' => '4'],
        ]);

        expect($review['author'])->toBe('Custom Author')
            ->and($review['reviewRating']['ratingValue'])->toBe('4');
    });
});
