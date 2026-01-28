<?php

declare(strict_types=1);

use App\Crawler\DTOs\PaginatedUrl;
use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\Sainsburys\SainsburysProductListingUrlExtractor;
use App\Crawler\Services\CategoryExtractor;

beforeEach(function () {
    $this->extractor = new SainsburysProductListingUrlExtractor(
        app(CategoryExtractor::class)
    );
});

describe('canHandle', function () {
    test('returns true for shop pages', function () {
        expect($this->extractor->canHandle('https://www.sainsburys.co.uk/shop/gb/groceries/pets/dog'))
            ->toBeTrue();
    });

    test('returns true for gol-ui browse pages', function () {
        expect($this->extractor->canHandle('https://www.sainsburys.co.uk/gol-ui/groceries/dog-food'))
            ->toBeTrue();
    });

    test('returns true for category pages', function () {
        expect($this->extractor->canHandle('https://www.sainsburys.co.uk/shop/gb/groceries/pets/dog/dog-food'))
            ->toBeTrue();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/shop/pets'))
            ->toBeFalse();
    });
});

describe('product URL extraction', function () {
    test('extracts product URLs from category page', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/sainsburys-category-page.html');
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets/dog/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        // The fixture contains gol-ui/product URLs with --[digits] pattern
        // and /product/ URLs with -[digits] pattern (2 links match the isProductUrl patterns)
        expect($productUrls)->not->toBeEmpty()
            ->and(count($productUrls))->toBeGreaterThanOrEqual(2);
    });

    test('extracts product URLs from anchor tags with gol-ui format', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/gol-ui/product/pedigree-vital-protection-adult--7878567">Product 1</a>
            <a href="/gol-ui/product/royal-canin-medium-adult--1234567">Product 2</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->toHaveCount(2);
    });

    test('extracts product URLs from alternative format', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/product/test-product-1234567">Product 1</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->toHaveCount(1);
    });

    test('extracts product URLs from alternative product format', function () {
        // The extractor filters on a[href*="/product/"], then validates with isProductUrl
        // The /product/[name]-[digits] pattern should work
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/product/harringtons-complete-dry-2345678">Product</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->toHaveCount(1);
    });

    test('normalizes relative URLs to absolute', function () {
        $html = '<html><body><a href="/gol-ui/product/test-product--123456">Product</a></body></html>';
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->url)->toStartWith('https://www.sainsburys.co.uk');
    });

    test('deduplicates product URLs', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/gol-ui/product/same-product--123456">Product</a>
            <a href="/gol-ui/product/same-product--123456">Same Product</a>
            <a href="https://www.sainsburys.co.uk/gol-ui/product/same-product--123456">Same Again</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->toHaveCount(1);
    });

    test('includes retailer information in DTO', function () {
        $html = '<html><body><a href="/gol-ui/product/test--123456">Product</a></body></html>';
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->retailer)->toBe('sainsburys');
    });

    test('includes metadata with discovery information', function () {
        $html = '<html><body><a href="/gol-ui/product/test--123456">Product</a></body></html>';
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->metadata)->toHaveKey('discovered_from')
            ->and($productUrls[0]->metadata['discovered_from'])->toBe($url);
    });

    test('extracts product URLs from inline JSON data', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <script>
                var data = {"url": "/gol-ui/product/test-from-json--999999"};
            </script>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->not->toBeEmpty();
    });
});

describe('category extraction', function () {
    test('extracts dog-food category from pets URL', function () {
        $html = '<html><body><a href="/gol-ui/product/test--123456">Product</a></body></html>';
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets/dog-food-and-treats';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->category)->toBe('dog-food-and-treats');
    });

    test('extracts category from gol-ui URL', function () {
        $html = '<html><body><a href="/gol-ui/product/test--123456">Product</a></body></html>';
        $url = 'https://www.sainsburys.co.uk/gol-ui/groceries/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->category)->toBe('dog-food');
    });

    test('extracts dog category from URL', function () {
        $html = '<html><body><a href="/gol-ui/product/test--123456">Product</a></body></html>';
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/dog/dry-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        // extractCategory matches /dog/ in URL and returns just "dog"
        expect($productUrls[0]->category)->toBe('dog');
    });
});

describe('pagination extraction', function () {
    test('extracts next page URL from pagination', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/sainsburys-category-page.html');
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets/dog/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $paginatedUrls = array_filter($results, fn ($item) => $item instanceof PaginatedUrl);

        expect($paginatedUrls)->not->toBeEmpty();
    });

    test('includes page number in paginated URL', function () {
        $html = '<html><body><a href="/gol-ui/product/test--123456">Product</a><a href="/shop/gb/groceries/pets/dog?page=2" rel="next">Next</a></body></html>';
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $paginatedUrls = array_filter($results, fn ($item) => $item instanceof PaginatedUrl);
        $paginatedUrls = array_values($paginatedUrls);

        expect($paginatedUrls)->toHaveCount(1)
            ->and($paginatedUrls[0]->page)->toBe(2)
            ->and($paginatedUrls[0]->retailer)->toBe('sainsburys');
    });
});

describe('extractProductCodeFromUrl', function () {
    test('extracts product code from gol-ui URL', function () {
        $code = $this->extractor->extractProductCodeFromUrl('https://www.sainsburys.co.uk/gol-ui/product/pedigree-vital-protection-adult--7878567');

        expect($code)->toBe('7878567');
    });

    test('extracts product code from alternative URL format', function () {
        $code = $this->extractor->extractProductCodeFromUrl('https://www.sainsburys.co.uk/product/test-product-1234567');

        expect($code)->toBe('1234567');
    });

    test('extracts product code from shop/gb/groceries URL', function () {
        $code = $this->extractor->extractProductCodeFromUrl('https://www.sainsburys.co.uk/shop/gb/groceries/dog-food/harringtons-complete-dry-dog-food--2345678');

        expect($code)->toBe('2345678');
    });

    test('returns slug for URLs without numeric code', function () {
        $code = $this->extractor->extractProductCodeFromUrl('https://www.sainsburys.co.uk/gol-ui/product/pedigree-vital-protection-adult');

        expect($code)->toBe('pedigree-vital-protection-adult');
    });

    test('returns null for invalid URLs', function () {
        $code = $this->extractor->extractProductCodeFromUrl('https://www.sainsburys.co.uk/about-us');

        expect($code)->toBeNull();
    });
});

describe('edge cases', function () {
    test('handles empty HTML gracefully', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles malformed links gracefully', function () {
        $html = '<html><body><a href="">Empty Link</a><a>No href</a></body></html>';
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('skips non-product links', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/shop/gb/groceries/pets">Category Link</a>
            <a href="/search?q=dog">Search Link</a>
            <a href="/about-us">About Link</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->toBeEmpty();
    });

    test('validates product URL format', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/gol-ui/product/valid-product--7878567">Valid Product</a>
            <a href="/gol-ui/browse/category">Category Page</a>
            <a href="/gol-ui/search">Search Page</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/shop/gb/groceries/pets';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->toHaveCount(1);
    });
});
