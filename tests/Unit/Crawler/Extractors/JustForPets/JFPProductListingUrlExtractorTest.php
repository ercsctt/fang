<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\JustForPets\JFPProductListingUrlExtractor;
use App\Crawler\Services\CategoryExtractor;

beforeEach(function () {
    $this->extractor = new JFPProductListingUrlExtractor(
        app(CategoryExtractor::class)
    );
});

describe('canHandle', function () {
    test('returns true for justforpetsonline.co.uk category URLs', function () {
        expect($this->extractor->canHandle('https://www.justforpetsonline.co.uk/dog/dog-food/'))
            ->toBeTrue();
    });

    test('returns true for justforpetsonline.co.uk URLs without www', function () {
        expect($this->extractor->canHandle('https://justforpetsonline.co.uk/dog/dog-treats/'))
            ->toBeTrue();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.petsathome.com/dog/dog-food'))
            ->toBeFalse();
    });

    test('returns false for amazon URLs', function () {
        expect($this->extractor->canHandle('https://www.amazon.co.uk/s?k=dog+food'))
            ->toBeFalse();
    });
});

describe('product URL extraction', function () {
    test('extracts product URLs from HTML with /product/ pattern', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/product/pedigree-adult-dog-food-12kg-123">Product 1</a>
            <a href="/product/royal-canin-puppy-food-456">Product 2</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/dog/dog-food/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(2);
    });

    test('extracts product URLs from HTML with /products/ pattern', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/products/harringtons-complete-adult-789">Product 1</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/dog/dog-food/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });

    test('extracts product URLs from HTML with slash p slash pattern', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/p/12345">Product 1</a>
            <a href="/p/67890">Product 2</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/dog/dog-food/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(2);
    });

    test('extracts product URLs from HTML with dash p dash pattern', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/dog-food/pedigree-chicken-p-12345.html">Product 1</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/dog/dog-food/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });

    test('extracts product URLs from HTML with slug-id.html pattern', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/dog-food/pedigree-chicken-12345.html">Product 1</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/dog/dog-food/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });

    test('yields ProductListingUrl DTOs with correct structure', function () {
        $html = '<html><body><a href="/product/test-product-123">Product</a></body></html>';
        $url = 'https://www.justforpetsonline.co.uk/dog/dog-food/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0])->toBeInstanceOf(ProductListingUrl::class)
            ->and($results[0]->url)->toContain('justforpetsonline.co.uk/product/test-product-123')
            ->and($results[0]->retailer)->toBe('just-for-pets')
            ->and($results[0]->metadata)->toHaveKey('discovered_from');
    });

    test('normalizes relative URLs to absolute', function () {
        $html = '<html><body><a href="/product/test-product-123">Product</a></body></html>';
        $url = 'https://www.justforpetsonline.co.uk/dog/dog-food/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->url)->toStartWith('https://');
    });

    test('deduplicates product URLs', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/product/test-product-123">Product 1</a>
            <a href="/product/test-product-123">Product 1 again</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/dog/dog-food/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });
});

describe('external ID extraction from URL', function () {
    it('extracts ID from product URLs', function (string $productUrl, string $expectedId) {
        expect($this->extractor->extractExternalIdFromUrl($productUrl))->toBe($expectedId);
    })->with([
        '/product/slug-123' => ['https://www.justforpetsonline.co.uk/product/pedigree-food-123', '123'],
        '/products/slug-456' => ['https://www.justforpetsonline.co.uk/products/royal-canin-456', '456'],
        '/p/12345' => ['https://www.justforpetsonline.co.uk/p/12345', '12345'],
        '-p-789.html' => ['https://www.justforpetsonline.co.uk/dog-food/test-p-789.html', '789'],
        'slug-123.html' => ['https://www.justforpetsonline.co.uk/dog-food/test-product-123.html', '123'],
    ]);

    test('extracts slug as ID for .html URLs without numeric ID', function () {
        $url = 'https://www.justforpetsonline.co.uk/dog-food/premium-product.html';

        expect($this->extractor->extractExternalIdFromUrl($url))->toBe('premium-product');
    });
});

describe('category extraction', function () {
    test('extracts dog category from URL', function () {
        $html = '<html><body><a href="/product/test-123">Product</a></body></html>';
        $url = 'https://www.justforpetsonline.co.uk/dog/dog-food/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('dog');
    });

    test('extracts dog category from dog treats URL', function () {
        $html = '<html><body><a href="/product/test-123">Product</a></body></html>';
        $url = 'https://www.justforpetsonline.co.uk/dog/dog-treats/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('dog');
    });

    test('extracts dog category from puppy URL', function () {
        $html = '<html><body><a href="/product/test-123">Product</a></body></html>';
        $url = 'https://www.justforpetsonline.co.uk/dog/puppy/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('dog');
    });

    test('extracts puppy-food category from puppy food URL', function () {
        $html = '<html><body><a href="/product/test-123">Product</a></body></html>';
        $url = 'https://www.justforpetsonline.co.uk/puppy-food/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('puppy-food');
    });
});

describe('metadata', function () {
    test('includes discovery information in metadata', function () {
        $html = '<html><body><a href="/product/test-123">Product</a></body></html>';
        $url = 'https://www.justforpetsonline.co.uk/dog/dog-food/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('discovered_from')
            ->and($results[0]->metadata['discovered_from'])->toBe($url)
            ->and($results[0]->metadata)->toHaveKey('discovered_at');
    });
});

describe('edge cases', function () {
    test('handles empty HTML', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.justforpetsonline.co.uk/dog/dog-food/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles HTML with no product links', function () {
        $html = '<html><body><a href="/about">About</a><a href="/contact">Contact</a></body></html>';
        $url = 'https://www.justforpetsonline.co.uk/dog/dog-food/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles malformed HTML gracefully', function () {
        $html = '<html><body><a href="/product/test-123">Product<div></body>';
        $url = 'https://www.justforpetsonline.co.uk/dog/dog-food/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });

    test('skips navigation and category links', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/dog/dog-food/">Dog Food Category</a>
            <a href="/about-us">About Us</a>
            <a href="/product/real-product-123">Real Product</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/dog/dog-food/';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });
});
