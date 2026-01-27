<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\Morrisons\MorrisonsProductListingUrlExtractor;

beforeEach(function () {
    $this->extractor = new MorrisonsProductListingUrlExtractor;
});

describe('canHandle', function () {
    test('returns true for morrisons.com category URLs', function () {
        expect($this->extractor->canHandle('https://groceries.morrisons.com/browse/pet/dog'))
            ->toBeTrue();
    });

    test('returns true for morrisons.com browse pages', function () {
        expect($this->extractor->canHandle('https://groceries.morrisons.com/browse/pet/dog/dry-dog-food'))
            ->toBeTrue();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/groceries/en-GB/shop/pets/'))
            ->toBeFalse();
    });

    test('returns false for product URLs (those need MorrisonsProductDetailsExtractor)', function () {
        expect($this->extractor->canHandle('https://groceries.morrisons.com/products/pedigree-dog-food/123456'))
            ->toBeFalse();
    });
});

describe('product URL extraction', function () {
    test('extracts product URLs from category page HTML', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="product-list">
                <a href="/products/pedigree-dry-dog-food-2kg/123456">Pedigree Dog Food</a>
                <a href="/products/whiskas-cat-food-1kg/789012">Whiskas Cat Food</a>
                <a href="/products/royal-canin-puppy-food/345678">Royal Canin</a>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/browse/pet/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(3);
    });

    test('yields ProductListingUrl DTOs with correct structure', function () {
        $html = '<html><body><a href="/products/pedigree-dog-food/123456">Product</a></body></html>';
        $url = 'https://groceries.morrisons.com/browse/pet/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0])->toBeInstanceOf(ProductListingUrl::class)
            ->and($results[0]->url)->toBe('https://groceries.morrisons.com/products/pedigree-dog-food/123456')
            ->and($results[0]->retailer)->toBe('morrisons');
    });

    test('normalizes relative URLs to absolute', function () {
        $html = '<html><body><a href="/products/pedigree-dog-food/123456">Product</a></body></html>';
        $url = 'https://groceries.morrisons.com/browse/pet/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->url)->toStartWith('https://groceries.morrisons.com');
    });

    test('deduplicates product URLs', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/products/pedigree-dog-food/123456">Product 1</a>
            <a href="/products/pedigree-dog-food/123456">Product 1 again</a>
            <a href="/products/pedigree-dog-food/123456">Product 1 another</a>
        </body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/browse/pet/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });

    test('extracts product URLs with alphanumeric SKUs', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/products/pedigree-adult-dog-food/12345678">Numeric SKU</a>
            <a href="/products/whiskas-cat-food/ABC12345">Alphanumeric SKU</a>
        </body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/browse/pet/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(2);
    });
});

describe('category extraction', function () {
    test('extracts category from browse URL path', function () {
        $html = '<html><body><a href="/products/pedigree-dog-food/123456">Product</a></body></html>';
        $url = 'https://groceries.morrisons.com/browse/pet/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('dog');
    });

    test('extracts dog food category from URL', function () {
        $html = '<html><body><a href="/products/pedigree-dog-food/123456">Product</a></body></html>';
        $url = 'https://groceries.morrisons.com/browse/pet/dog/dry-dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('dog');
    });

    test('returns null for URLs without clear category', function () {
        $html = '<html><body><a href="/products/pedigree-dog-food/123456">Product</a></body></html>';
        $url = 'https://groceries.morrisons.com/search?q=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBeNull();
    });
});

describe('metadata', function () {
    test('includes discovery information in metadata', function () {
        $html = '<html><body><a href="/products/pedigree-dog-food/123456">Product</a></body></html>';
        $url = 'https://groceries.morrisons.com/browse/pet/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('discovered_from')
            ->and($results[0]->metadata['discovered_from'])->toBe($url)
            ->and($results[0]->metadata)->toHaveKey('discovered_at');
    });
});

describe('edge cases', function () {
    test('handles empty HTML', function () {
        $html = '<html><body></body></html>';
        $url = 'https://groceries.morrisons.com/browse/pet/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles HTML with no product links', function () {
        $html = '<html><body><a href="/about">About</a></body></html>';
        $url = 'https://groceries.morrisons.com/browse/pet/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles malformed HTML gracefully', function () {
        $html = '<html><body><a href="/products/pedigree-dog-food/123456">Product<div></body>';
        $url = 'https://groceries.morrisons.com/browse/pet/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });

    test('handles absolute URLs in links', function () {
        $html = '<html><body><a href="https://groceries.morrisons.com/products/pedigree-dog-food/123456">Product</a></body></html>';
        $url = 'https://groceries.morrisons.com/browse/pet/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->url)->toBe('https://groceries.morrisons.com/products/pedigree-dog-food/123456');
    });
});
