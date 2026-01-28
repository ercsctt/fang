<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\BM\BMProductListingUrlExtractor;
use App\Crawler\Services\CategoryExtractor;

beforeEach(function () {
    $this->extractor = new BMProductListingUrlExtractor(
        app(CategoryExtractor::class)
    );
});

describe('canHandle', function () {
    test('returns true for bmstores.co.uk URLs', function () {
        expect($this->extractor->canHandle('https://www.bmstores.co.uk/products/dog-food'))
            ->toBeTrue();
    });

    test('returns true for bmstores.co.uk without www', function () {
        expect($this->extractor->canHandle('https://bmstores.co.uk/products/dog-food'))
            ->toBeTrue();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/products/dog-food'))
            ->toBeFalse();
    });

    // The implementation now uses proper host checking with parse_url
    // This correctly rejects partial domain matches
    test('returns false for partial domain match', function () {
        expect($this->extractor->canHandle('https://www.notbmstores.co.uk/products'))
            ->toBeFalse();
    });
});

describe('URL normalization', function () {
    test('converts relative URLs to absolute', function () {
        $html = '<html><body><a href="/product/test-product-123456">Test</a></body></html>';
        $baseUrl = 'https://www.bmstores.co.uk/pets/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results)->toHaveCount(1)
            ->and($results[0]->url)->toBe('https://www.bmstores.co.uk/product/test-product-123456');
    });

    test('handles already absolute URLs', function () {
        $html = '<html><body><a href="https://www.bmstores.co.uk/product/test-product-123456">Test</a></body></html>';
        $baseUrl = 'https://www.bmstores.co.uk/pets/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results)->toHaveCount(1)
            ->and($results[0]->url)->toBe('https://www.bmstores.co.uk/product/test-product-123456');
    });

    test('handles protocol-relative URLs', function () {
        $html = '<html><body><a href="//www.bmstores.co.uk/product/test-product-123456">Test</a></body></html>';
        $baseUrl = 'https://www.bmstores.co.uk/pets/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results)->toHaveCount(1)
            ->and($results[0]->url)->toBe('https://www.bmstores.co.uk/product/test-product-123456');
    });

    // The extractor now scans ALL links and normalizes relative paths
    // This ensures we don't miss product URLs regardless of link format
    test('handles relative path URLs without leading slash', function () {
        $html = '<html><body><a href="product/test-123456">Test</a></body></html>';
        $baseUrl = 'https://www.bmstores.co.uk/pets/';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results)->toHaveCount(1);
        expect($results[0]->url)->toBe('https://www.bmstores.co.uk/pets/product/test-123456');
    });
});

describe('extraction from HTML fixtures', function () {
    test('extracts product URLs from category page', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-category-page.html');
        $baseUrl = 'https://www.bmstores.co.uk/pets/dog/food';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        // Extractor now scans ALL links and validates via isProductUrl()
        // This catches /product/, /p/, and /pd/ patterns throughout the page
        // Duplicates are automatically removed
        expect($results)->toHaveCount(7);

        $urls = array_map(fn ($dto) => $dto->url, $results);

        expect($urls)->toContain('https://www.bmstores.co.uk/product/pedigree-adult-dry-dog-food-12kg-123456')
            ->toContain('https://www.bmstores.co.uk/product/royal-canin-medium-adult-4kg-234567')
            ->toContain('https://www.bmstores.co.uk/product/bakers-complete-2kg-345678')
            ->toContain('https://www.bmstores.co.uk/product/harringtons-lamb-10kg-456789')
            ->toContain('https://www.bmstores.co.uk/product/wagg-complete-15kg-567890')
            ->toContain('https://www.bmstores.co.uk/p/678901')
            ->toContain('https://www.bmstores.co.uk/pd/iams-adult-chicken');
    });

    test('yields ProductListingUrl DTOs', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-category-page.html');
        $baseUrl = 'https://www.bmstores.co.uk/pets/dog/food';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        foreach ($results as $dto) {
            expect($dto)->toBeInstanceOf(ProductListingUrl::class);
        }
    });

    test('sets retailer to bm', function () {
        $html = '<html><body><a href="/product/test-123456">Test</a></body></html>';
        $baseUrl = 'https://www.bmstores.co.uk/pets/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results[0]->retailer)->toBe('bm');
    });

    test('includes metadata with discovery info', function () {
        $html = '<html><body><a href="/product/test-123456">Test</a></body></html>';
        $baseUrl = 'https://www.bmstores.co.uk/pets/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results[0]->metadata)->toHaveKey('discovered_from')
            ->and($results[0]->metadata['discovered_from'])->toBe($baseUrl)
            ->and($results[0]->metadata)->toHaveKey('discovered_at');
    });
});

describe('deduplication', function () {
    test('removes duplicate URLs', function () {
        $html = '
            <html><body>
                <a href="/product/test-123456">Test</a>
                <a href="/product/test-123456">Test Duplicate</a>
                <a href="/product/test-123456">Test Another Duplicate</a>
            </body></html>
        ';
        $baseUrl = 'https://www.bmstores.co.uk/pets';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results)->toHaveCount(1);
    });

    test('keeps URLs that differ only by query string', function () {
        $html = '
            <html><body>
                <a href="/product/test-123456">Test</a>
                <a href="/product/test-123456?color=red">Test with query</a>
            </body></html>
        ';
        $baseUrl = 'https://www.bmstores.co.uk/pets';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        // Both URLs are different, so both should be included
        expect($results)->toHaveCount(2);
    });
});

describe('category extraction from URL', function () {
    test('extracts dog category from URL', function () {
        $html = '<html><body><a href="/dog/food/product/test-123456">Test</a></body></html>';
        $baseUrl = 'https://www.bmstores.co.uk/pets';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results[0]->category)->toBe('dog');
    });

    test('extracts pet category from URL', function () {
        $html = '<html><body><a href="/pet-food/product/test-123456">Test</a></body></html>';
        $baseUrl = 'https://www.bmstores.co.uk/pets';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results[0]->category)->toBe('pet');
    });

    test('extracts cat category from URL', function () {
        $html = '<html><body><a href="/cat/food/product/test-123456">Test</a></body></html>';
        $baseUrl = 'https://www.bmstores.co.uk/pets';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results[0]->category)->toBe('cat');
    });

    test('returns null when no category in URL', function () {
        $html = '<html><body><a href="/product/test-123456">Test</a></body></html>';
        $baseUrl = 'https://www.bmstores.co.uk/pets';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results[0]->category)->toBeNull();
    });
});

describe('product URL validation', function () {
    test('accepts URLs with /product/ pattern', function () {
        $html = '<html><body><a href="/product/any-name-123">Test</a></body></html>';
        $baseUrl = 'https://www.bmstores.co.uk';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results)->toHaveCount(1);
    });

    // The extractor scans all links and validates via isProductUrl()
    // Container classes are no longer required for pattern matching
    test('accepts URLs with /p/{number} pattern', function () {
        $html = '<html><body><div class="product-tile"><a href="/p/12345">Test</a></div></body></html>';
        $baseUrl = 'https://www.bmstores.co.uk';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results)->toHaveCount(1)
            ->and($results[0]->url)->toBe('https://www.bmstores.co.uk/p/12345');
    });

    test('accepts URLs with /pd/{slug} pattern', function () {
        $html = '<html><body><div class="product-card"><a href="/pd/product-name-slug">Test</a></div></body></html>';
        $baseUrl = 'https://www.bmstores.co.uk';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results)->toHaveCount(1)
            ->and($results[0]->url)->toBe('https://www.bmstores.co.uk/pd/product-name-slug');
    });

    test('finds /p/ or /pd/ URLs regardless of container', function () {
        $html = '<html><body><a href="/p/12345">Test</a><a href="/pd/slug">Test 2</a></body></html>';
        $baseUrl = 'https://www.bmstores.co.uk';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results)->toHaveCount(2);
        expect($results[0]->url)->toBe('https://www.bmstores.co.uk/p/12345');
        expect($results[1]->url)->toBe('https://www.bmstores.co.uk/pd/slug');
    });

    test('rejects non-product URLs', function () {
        $html = '
            <html><body>
                <a href="/about">About</a>
                <a href="/contact">Contact</a>
                <a href="/category/dog-food">Category</a>
            </body></html>
        ';
        $baseUrl = 'https://www.bmstores.co.uk';

        $results = iterator_to_array($this->extractor->extract($html, $baseUrl));

        expect($results)->toHaveCount(0);
    });
});

describe('edge cases', function () {
    test('handles empty HTML', function () {
        $results = iterator_to_array($this->extractor->extract('', 'https://www.bmstores.co.uk'));

        expect($results)->toHaveCount(0);
    });

    test('handles HTML with no links', function () {
        $html = '<html><body><p>No links here</p></body></html>';

        $results = iterator_to_array($this->extractor->extract($html, 'https://www.bmstores.co.uk'));

        expect($results)->toHaveCount(0);
    });

    test('handles HTML with empty href attributes', function () {
        $html = '<html><body><a href="">Empty</a><a href="/product/test-123">Valid</a></body></html>';

        $results = iterator_to_array($this->extractor->extract($html, 'https://www.bmstores.co.uk'));

        expect($results)->toHaveCount(1);
    });

    test('handles malformed HTML gracefully', function () {
        $html = '<html><body><a href="/product/test-123">Test<div>Nested</div></a></html>';

        $results = iterator_to_array($this->extractor->extract($html, 'https://www.bmstores.co.uk'));

        expect($results)->toHaveCount(1);
    });
});
