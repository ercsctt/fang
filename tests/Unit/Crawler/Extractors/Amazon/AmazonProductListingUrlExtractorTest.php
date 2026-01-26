<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\Amazon\AmazonProductListingUrlExtractor;

beforeEach(function () {
    $this->extractor = new AmazonProductListingUrlExtractor;
});

describe('canHandle', function () {
    test('returns true for amazon.co.uk search URLs', function () {
        expect($this->extractor->canHandle('https://www.amazon.co.uk/s?k=dog+food'))
            ->toBeTrue();
    });

    test('returns true for amazon.co.uk category URLs with /s/', function () {
        expect($this->extractor->canHandle('https://www.amazon.co.uk/s/ref=nb_sb_noss?url=search-alias%3Dpets&field-keywords=dog+food'))
            ->toBeTrue();
    });

    test('returns true for amazon.co.uk browse node URLs', function () {
        expect($this->extractor->canHandle('https://www.amazon.co.uk/b/?node=471382031'))
            ->toBeTrue();
    });

    test('returns false for amazon.co.uk product detail pages', function () {
        expect($this->extractor->canHandle('https://www.amazon.co.uk/dp/B08L5WRMZJ'))
            ->toBeFalse();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.amazon.com/s?k=dog+food'))
            ->toBeFalse();
    });
});

describe('ASIN extraction', function () {
    test('extracts product URLs from search results page HTML', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="s-result-list">
                <a href="/dp/B08L5WRMZJ/ref=sr_1_1?k=dog+food">Product 1</a>
                <a href="/dp/B07N4CXL1Y/ref=sr_1_2?k=dog+food">Product 2</a>
                <a href="/gp/product/B09ABC1234/ref=sr_1_3">Product 3</a>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(3);
    });

    test('yields ProductListingUrl DTOs with correct structure', function () {
        $html = '<html><body><a href="/dp/B08L5WRMZJ">Product</a></body></html>';
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0])->toBeInstanceOf(ProductListingUrl::class)
            ->and($results[0]->url)->toBe('https://www.amazon.co.uk/dp/B08L5WRMZJ')
            ->and($results[0]->retailer)->toBe('amazon-uk')
            ->and($results[0]->metadata)->toHaveKey('asin')
            ->and($results[0]->metadata['asin'])->toBe('B08L5WRMZJ');
    });

    test('normalizes product URLs to canonical form', function () {
        $html = '<html><body><a href="/dp/B08L5WRMZJ/ref=sr_1_1?k=dog+food&qid=12345">Product</a></body></html>';
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->url)->toBe('https://www.amazon.co.uk/dp/B08L5WRMZJ');
    });

    test('extracts ASIN from /gp/product/ URLs', function () {
        $html = '<html><body><a href="/gp/product/B07N4CXL1Y">Product</a></body></html>';
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['asin'])->toBe('B07N4CXL1Y');
    });

    test('extracts ASIN from /gp/aw/d/ mobile URLs', function () {
        $html = '<html><body><a href="/gp/aw/d/B09XYZ1234">Mobile Product</a></body></html>';
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['asin'])->toBe('B09XYZ1234');
    });

    test('deduplicates products by ASIN', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/dp/B08L5WRMZJ">Product 1</a>
            <a href="/dp/B08L5WRMZJ/ref=sr_1_1">Product 1 again</a>
            <a href="/gp/product/B08L5WRMZJ">Product 1 another format</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });
});

describe('category extraction', function () {
    test('extracts category from search query', function () {
        $html = '<html><body><a href="/dp/B08L5WRMZJ">Product</a></body></html>';
        $url = 'https://www.amazon.co.uk/s?k=dog+food&rh=n%3A471382031';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('dog-food');
    });

    test('extracts category from search query with treats', function () {
        $html = '<html><body><a href="/dp/B08L5WRMZJ">Product</a></body></html>';
        $url = 'https://www.amazon.co.uk/s?k=dog+treats';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('dog-treats');
    });

    test('extracts category from URL path', function () {
        $html = '<html><body><a href="/dp/B08L5WRMZJ">Product</a></body></html>';
        $url = 'https://www.amazon.co.uk/Pet-Supplies/b/?node=471382031';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->category)->toBe('Pet Supplies');
    });
});

describe('metadata', function () {
    test('includes discovery information in metadata', function () {
        $html = '<html><body><a href="/dp/B08L5WRMZJ">Product</a></body></html>';
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('discovered_from')
            ->and($results[0]->metadata['discovered_from'])->toBe($url)
            ->and($results[0]->metadata)->toHaveKey('discovered_at')
            ->and($results[0]->metadata)->toHaveKey('asin');
    });
});

describe('edge cases', function () {
    test('handles empty HTML', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles HTML with no product links', function () {
        $html = '<html><body><a href="/about">About</a></body></html>';
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles malformed HTML gracefully', function () {
        $html = '<html><body><a href="/dp/B08L5WRMZJ">Product<div></body>';
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });

    test('skips invalid ASIN formats', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/dp/B08L5WRMZJ">Valid ASIN</a>
            <a href="/dp/SHORT">Too short</a>
            <a href="/dp/TOOLONGASIN12345">Too long</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/s?k=dog+food';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });
});
