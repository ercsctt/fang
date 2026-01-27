<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\Zooplus\ZooplusProductListingUrlExtractor;

beforeEach(function () {
    $this->extractor = new ZooplusProductListingUrlExtractor;
});

describe('canHandle', function () {
    test('returns true for zooplus.co.uk category URLs', function () {
        expect($this->extractor->canHandle('https://www.zooplus.co.uk/shop/dogs/dry_dog_food'))
            ->toBeTrue();
    });

    test('returns true for zooplus.co.uk product listing pages', function () {
        expect($this->extractor->canHandle('https://www.zooplus.co.uk/shop/dogs/dog_treats_chews/dog_biscuits_treats'))
            ->toBeTrue();
    });

    test('returns false for zooplus.co.uk product pages', function () {
        expect($this->extractor->canHandle('https://www.zooplus.co.uk/shop/dogs/dry_dog_food/royal_canin/royal-canin-maxi-adult_123456'))
            ->toBeFalse();
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
                <a href="/shop/dogs/dry_dog_food/royal_canin/royal-canin-maxi-adult_123456">Royal Canin Maxi Adult</a>
                <a href="/shop/dogs/dry_dog_food/hills/hills-science-plan_234567">Hills Science Plan</a>
                <a href="/shop/dogs/wet_dog_food/pedigree/pedigree-adult_345678">Pedigree Adult</a>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(3);
    });

    test('yields ProductListingUrl DTOs with correct structure', function () {
        $html = '<html><body><a href="/shop/dogs/dry_dog_food/brand/product-name_123456">Product</a></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0])->toBeInstanceOf(ProductListingUrl::class)
            ->and($results[0]->url)->toBe('https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product-name_123456')
            ->and($results[0]->retailer)->toBe('zooplus-uk');
    });

    test('normalizes relative URLs to absolute', function () {
        $html = '<html><body><a href="/shop/dogs/dry_dog_food/brand/product-name_123456">Product</a></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->url)->toStartWith('https://www.zooplus.co.uk');
    });

    test('deduplicates product URLs', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/shop/dogs/dry_dog_food/brand/product-name_123456">Product 1</a>
            <a href="/shop/dogs/dry_dog_food/brand/product-name_123456">Product 1 again</a>
            <a href="/shop/dogs/dry_dog_food/brand/product-name_123456">Product 1 another</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });

    test('extracts numeric product IDs', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/shop/dogs/dry_dog_food/royal_canin/royal-canin-maxi_123456">Valid numeric ID</a>
            <a href="/shop/dogs/wet_dog_food/pedigree/pedigree-chunks_987654">Valid with different ID</a>
            <a href="/shop/dogs/dry_dog_food/">Category link</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(2)
            ->and($results[0]->url)->toContain('_123456')
            ->and($results[1]->url)->toContain('_987654');
    });
});

describe('category extraction', function () {
    test('extracts category from shop URL path', function () {
        $html = '<html><body><a href="/shop/dogs/dry_dog_food/brand/product_123456">Product</a></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('dry_dog_food');
    });

    test('extracts dog treats category from URL', function () {
        $html = '<html><body><a href="/shop/dogs/dog_treats_chews/brand/product_123456">Product</a></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dog_treats_chews';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('dog_treats_chews');
    });

    test('extracts puppy food category from URL', function () {
        $html = '<html><body><a href="/shop/dogs/puppy_food/brand/product_123456">Product</a></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/puppy_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('puppy_food');
    });
});

describe('metadata', function () {
    test('includes discovery information in metadata', function () {
        $html = '<html><body><a href="/shop/dogs/dry_dog_food/brand/product_123456">Product</a></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('discovered_from')
            ->and($results[0]->metadata['discovered_from'])->toBe($url)
            ->and($results[0]->metadata)->toHaveKey('discovered_at');
    });
});

describe('edge cases', function () {
    test('handles empty HTML', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles HTML with no product links', function () {
        $html = '<html><body><a href="/about">About</a></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles malformed HTML gracefully', function () {
        $html = '<html><body><a href="/shop/dogs/dry_dog_food/brand/product_123456">Product<div></body>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });

    test('handles absolute URLs in links', function () {
        $html = '<html><body><a href="https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456">Product</a></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->url)->toBe('https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456');
    });

    test('ignores category links without product IDs', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/shop/dogs/dry_dog_food">Category Link</a>
            <a href="/shop/dogs/dry_dog_food/brand">Brand Link</a>
            <a href="/shop/dogs/dry_dog_food/brand/product_123456">Product Link</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->url)->toContain('_123456');
    });
});
