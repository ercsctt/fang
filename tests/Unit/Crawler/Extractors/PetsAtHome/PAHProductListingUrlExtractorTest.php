<?php

declare(strict_types=1);

use App\Crawler\DTOs\PaginatedUrl;
use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\PetsAtHome\PAHProductListingUrlExtractor;
use App\Crawler\Services\CategoryExtractor;

beforeEach(function () {
    $this->extractor = new PAHProductListingUrlExtractor(
        app(CategoryExtractor::class)
    );
});

describe('canHandle', function () {
    test('returns true for shop pages', function () {
        expect($this->extractor->canHandle('https://www.petsathome.com/shop/en/pets/dog/dog-food'))
            ->toBeTrue();
    });

    test('returns true for category pages', function () {
        expect($this->extractor->canHandle('https://www.petsathome.com/shop/en/pets/dog/dog-food/dry-dog-food'))
            ->toBeTrue();
    });

    test('returns true for search pages', function () {
        expect($this->extractor->canHandle('https://www.petsathome.com/search?q=dog%20food'))
            ->toBeTrue();
    });

    test('returns true for product listing pages', function () {
        expect($this->extractor->canHandle('https://www.petsathome.com/c/dog/dog-food'))
            ->toBeTrue();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/shop/pets'))
            ->toBeFalse();
    });
});

describe('product URL extraction', function () {
    test('extracts product URLs from category page', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/petsathome-category-page.html');
        $url = 'https://www.petsathome.com/shop/en/pets/dog/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->not->toBeEmpty()
            ->and(count($productUrls))->toBeGreaterThanOrEqual(4);
    });

    test('extracts product URLs from anchor tags', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/product/wainwrights-chicken/P71341">Product 1</a>
            <a href="/product/royal-canin-mini/7136893P">Product 2</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.petsathome.com/shop/en/pets/dog/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->toHaveCount(2);
    });

    test('normalizes relative URLs to absolute', function () {
        $html = '<html><body><a href="/product/test-product/P12345">Product</a></body></html>';
        $url = 'https://www.petsathome.com/shop/en/pets/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->url)->toStartWith('https://www.petsathome.com');
    });

    test('deduplicates product URLs', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/product/same-product/P12345">Product</a>
            <a href="/product/same-product/P12345">Same Product</a>
            <a href="https://www.petsathome.com/product/same-product/P12345">Same Again</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.petsathome.com/shop/en/pets/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->toHaveCount(1);
    });

    test('includes retailer information in DTO', function () {
        $html = '<html><body><a href="/product/test/P12345">Product</a></body></html>';
        $url = 'https://www.petsathome.com/shop/en/pets/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->retailer)->toBe('pets-at-home');
    });

    test('includes metadata with discovery information', function () {
        $html = '<html><body><a href="/product/test/P12345">Product</a></body></html>';
        $url = 'https://www.petsathome.com/shop/en/pets/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->metadata)->toHaveKey('discovered_from')
            ->and($productUrls[0]->metadata['discovered_from'])->toBe($url);
    });
});

describe('category extraction', function () {
    test('extracts dog-food category from URL', function () {
        $html = '<html><body><a href="/product/test/P12345">Product</a></body></html>';
        $url = 'https://www.petsathome.com/shop/en/pets/dog/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->category)->toBe('dog-food');
    });

    test('extracts dog-treats category from URL', function () {
        $html = '<html><body><a href="/product/test/P12345">Product</a></body></html>';
        $url = 'https://www.petsathome.com/shop/en/pets/dog/dog-treats';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->category)->toBe('dog-treats');
    });

    test('extracts cat-food category from URL', function () {
        $html = '<html><body><a href="/product/test/P12345">Product</a></body></html>';
        $url = 'https://www.petsathome.com/shop/en/pets/cat/cat-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->category)->toBe('cat-food');
    });

    test('extracts animal category when no specific type', function () {
        $html = '<html><body><a href="/product/test/P12345">Product</a></body></html>';
        $url = 'https://www.petsathome.com/shop/en/pets/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->category)->toBe('dog');
    });
});

describe('pagination extraction', function () {
    test('extracts next page URL from pagination', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/petsathome-category-page.html');
        $url = 'https://www.petsathome.com/shop/en/pets/dog/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $paginatedUrls = array_filter($results, fn ($item) => $item instanceof PaginatedUrl);

        expect($paginatedUrls)->not->toBeEmpty();
    });

    test('includes page number in paginated URL', function () {
        $html = '<html><body><a href="/product/test/P12345">Product</a><a href="/shop/en/pets/dog/dog-food?page=2" rel="next">Next</a></body></html>';
        $url = 'https://www.petsathome.com/shop/en/pets/dog/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $paginatedUrls = array_filter($results, fn ($item) => $item instanceof PaginatedUrl);
        $paginatedUrls = array_values($paginatedUrls);

        expect($paginatedUrls)->toHaveCount(1)
            ->and($paginatedUrls[0]->page)->toBe(2)
            ->and($paginatedUrls[0]->retailer)->toBe('pets-at-home');
    });

    test('extracts category in paginated URL', function () {
        $html = '<html><body><a href="/product/test/P12345">Product</a><a href="?page=2" rel="next">Next</a></body></html>';
        $url = 'https://www.petsathome.com/shop/en/pets/dog/dog-food';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $paginatedUrls = array_filter($results, fn ($item) => $item instanceof PaginatedUrl);
        $paginatedUrls = array_values($paginatedUrls);

        expect($paginatedUrls[0]->category)->toBe('dog-food');
    });
});

describe('edge cases', function () {
    test('handles empty HTML gracefully', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.petsathome.com/shop/en/pets/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles malformed links gracefully', function () {
        $html = '<html><body><a href="">Empty Link</a><a>No href</a></body></html>';
        $url = 'https://www.petsathome.com/shop/en/pets/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('skips non-product links', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/shop/en/pets/dog">Category Link</a>
            <a href="/search?q=dog">Search Link</a>
            <a href="/about-us">About Link</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.petsathome.com/shop/en/pets/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->toBeEmpty();
    });

    test('validates product URL format with P prefix', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/product/valid-product-with-code/P12345">Valid Product 1</a>
            <a href="/product/valid-product-with-code/7136893P">Valid Product 2</a>
            <a href="/product/no-code">Invalid Product</a>
            <a href="/product/">Empty Product</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.petsathome.com/shop/en/pets/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->toHaveCount(2);
    });

    test('extracts full URLs from links', function () {
        $html = '<html><body><a href="https://www.petsathome.com/product/harringtons/P789012">Product</a></body></html>';
        $url = 'https://www.petsathome.com/shop/en/pets/dog';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls)->toHaveCount(1)
            ->and($productUrls[0]->url)->toBe('https://www.petsathome.com/product/harringtons/P789012');
    });
});
