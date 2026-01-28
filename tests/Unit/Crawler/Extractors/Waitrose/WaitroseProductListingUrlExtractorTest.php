<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\Waitrose\WaitroseProductListingUrlExtractor;

beforeEach(function () {
    $this->extractor = new WaitroseProductListingUrlExtractor;
});

describe('canHandle', function () {
    test('returns true for waitrose.com URLs', function () {
        expect($this->extractor->canHandle('https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food'))
            ->toBeTrue();
    });

    test('returns true for waitrose.com product listing pages', function () {
        expect($this->extractor->canHandle('https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food/dry_dog_food'))
            ->toBeTrue();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/'))
            ->toBeFalse();
    });
});

describe('product URL extraction', function () {
    test('extracts product URLs from category page HTML', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="product-list">
                <a href="/ecom/products/pedigree-dog-food-500g/123456-abc">Pedigree Dog Food</a>
                <a href="/ecom/products/whiskas-cat-food-400g/987654-xyz">Whiskas Cat Food</a>
                <a href="/ecom/products/royal-canin-2kg/111222-def">Royal Canin</a>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(3);
    });

    test('yields ProductListingUrl DTOs with correct structure', function () {
        $html = '<html><body><a href="/ecom/products/pedigree-dog-food-500g/123456-abc">Product</a></body></html>';
        $url = 'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0])->toBeInstanceOf(ProductListingUrl::class)
            ->and($results[0]->url)->toBe('https://www.waitrose.com/ecom/products/pedigree-dog-food-500g/123456-abc')
            ->and($results[0]->retailer)->toBe('waitrose');
    });

    test('normalizes relative URLs to absolute', function () {
        $html = '<html><body><a href="/ecom/products/pedigree-dog-food-500g/123456-abc">Product</a></body></html>';
        $url = 'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->url)->toStartWith('https://www.waitrose.com');
    });

    test('deduplicates product URLs', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/ecom/products/pedigree-dog-food-500g/123456-abc">Product 1</a>
            <a href="/ecom/products/pedigree-dog-food-500g/123456-abc">Product 1 again</a>
            <a href="/ecom/products/pedigree-dog-food-500g/123456-abc">Product 1 another</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });

    test('extracts alphanumeric product IDs', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/ecom/products/pedigree-dog-food-500g/123456-abc">Valid alphanumeric ID</a>
            <a href="/ecom/products/whiskas/xyz-987654-def">Valid with complex ID</a>
            <a href="/ecom/shop/browse/">Category link</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(2)
            ->and($results[0]->url)->toContain('123456-abc')
            ->and($results[1]->url)->toContain('xyz-987654-def');
    });
});

describe('category extraction', function () {
    test('extracts category from shop URL path', function () {
        $html = '<html><body><a href="/ecom/products/pedigree-dog-food-500g/123456-abc">Product</a></body></html>';
        $url = 'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food/dry_dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('dog_food');
    });

    test('extracts dog food category from URL', function () {
        $html = '<html><body><a href="/ecom/products/pedigree-dog-food-500g/123456-abc">Product</a></body></html>';
        $url = 'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_treats';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('dog_treats');
    });

    test('extracts puppy food category from URL', function () {
        $html = '<html><body><a href="/ecom/products/pedigree-dog-food-500g/123456-abc">Product</a></body></html>';
        $url = 'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food/puppy_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('dog_food');
    });
});

describe('metadata', function () {
    test('includes discovery information in metadata', function () {
        $html = '<html><body><a href="/ecom/products/pedigree-dog-food-500g/123456-abc">Product</a></body></html>';
        $url = 'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('discovered_from')
            ->and($results[0]->metadata['discovered_from'])->toBe($url)
            ->and($results[0]->metadata)->toHaveKey('discovered_at');
    });
});

describe('edge cases', function () {
    test('handles empty HTML', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles HTML with no product links', function () {
        $html = '<html><body><a href="/about">About</a></body></html>';
        $url = 'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles malformed HTML gracefully', function () {
        $html = '<html><body><a href="/ecom/products/pedigree-dog-food-500g/123456-abc">Product<div></body>';
        $url = 'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });

    test('handles absolute URLs in links', function () {
        $html = '<html><body><a href="https://www.waitrose.com/ecom/products/pedigree-dog-food-500g/123456-abc">Product</a></body></html>';
        $url = 'https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->url)->toBe('https://www.waitrose.com/ecom/products/pedigree-dog-food-500g/123456-abc');
    });
});
