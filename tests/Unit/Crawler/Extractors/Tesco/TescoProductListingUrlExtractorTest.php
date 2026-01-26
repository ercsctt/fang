<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\Tesco\TescoProductListingUrlExtractor;

beforeEach(function () {
    $this->extractor = new TescoProductListingUrlExtractor;
});

describe('canHandle', function () {
    test('returns true for tesco.com URLs', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/'))
            ->toBeTrue();
    });

    test('returns true for tesco.com product listing pages', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/all'))
            ->toBeTrue();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.amazon.co.uk/s?k=dog+food'))
            ->toBeFalse();
    });
});

describe('product URL extraction', function () {
    test('extracts product URLs from category page HTML', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="product-list">
                <a href="/groceries/en-GB/products/123456789">Pedigree Dog Food</a>
                <a href="/groceries/en-GB/products/987654321">Whiskas Cat Food</a>
                <a href="/groceries/en-GB/products/111222333">Royal Canin</a>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(3);
    });

    test('yields ProductListingUrl DTOs with correct structure', function () {
        $html = '<html><body><a href="/groceries/en-GB/products/123456789">Product</a></body></html>';
        $url = 'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0])->toBeInstanceOf(ProductListingUrl::class)
            ->and($results[0]->url)->toBe('https://www.tesco.com/groceries/en-GB/products/123456789')
            ->and($results[0]->retailer)->toBe('tesco');
    });

    test('normalizes relative URLs to absolute', function () {
        $html = '<html><body><a href="/groceries/en-GB/products/123456789">Product</a></body></html>';
        $url = 'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->url)->toStartWith('https://www.tesco.com');
    });

    test('deduplicates product URLs', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/groceries/en-GB/products/123456789">Product 1</a>
            <a href="/groceries/en-GB/products/123456789">Product 1 again</a>
            <a href="/groceries/en-GB/products/123456789">Product 1 another</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });

    test('only extracts numeric product IDs', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/groceries/en-GB/products/123456789">Valid numeric ID</a>
            <a href="/groceries/en-GB/products/abc123">Invalid alphanumeric</a>
            <a href="/groceries/en-GB/shop/pets/">Category link</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->url)->toContain('123456789');
    });
});

describe('category extraction', function () {
    test('extracts category from shop URL path', function () {
        $html = '<html><body><a href="/groceries/en-GB/products/123456789">Product</a></body></html>';
        $url = 'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/dry-dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('dog-food-and-treats');
    });

    test('extracts dog food category from URL', function () {
        $html = '<html><body><a href="/groceries/en-GB/products/123456789">Product</a></body></html>';
        $url = 'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/wet-dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('dog-food-and-treats');
    });

    test('extracts puppy food category from URL', function () {
        $html = '<html><body><a href="/groceries/en-GB/products/123456789">Product</a></body></html>';
        $url = 'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/puppy-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('dog-food-and-treats');
    });
});

describe('metadata', function () {
    test('includes discovery information in metadata', function () {
        $html = '<html><body><a href="/groceries/en-GB/products/123456789">Product</a></body></html>';
        $url = 'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('discovered_from')
            ->and($results[0]->metadata['discovered_from'])->toBe($url)
            ->and($results[0]->metadata)->toHaveKey('discovered_at');
    });
});

describe('edge cases', function () {
    test('handles empty HTML', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles HTML with no product links', function () {
        $html = '<html><body><a href="/about">About</a></body></html>';
        $url = 'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles malformed HTML gracefully', function () {
        $html = '<html><body><a href="/groceries/en-GB/products/123456789">Product<div></body>';
        $url = 'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });

    test('handles absolute URLs in links', function () {
        $html = '<html><body><a href="https://www.tesco.com/groceries/en-GB/products/123456789">Product</a></body></html>';
        $url = 'https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->url)->toBe('https://www.tesco.com/groceries/en-GB/products/123456789');
    });
});
