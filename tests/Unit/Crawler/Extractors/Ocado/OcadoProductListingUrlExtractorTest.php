<?php

declare(strict_types=1);

use App\Crawler\DTOs\PaginatedUrl;
use App\Crawler\DTOs\ProductListingUrl;
use App\Crawler\Extractors\Ocado\OcadoProductListingUrlExtractor;
use App\Crawler\Services\CategoryExtractor;

beforeEach(function () {
    $this->extractor = new OcadoProductListingUrlExtractor(
        app(CategoryExtractor::class)
    );
});

describe('canHandle', function () {
    test('returns true for browse pages', function () {
        expect($this->extractor->canHandle('https://www.ocado.com/browse/pets-20974/dog-111797'))
            ->toBeTrue();
    });

    test('returns true for search pages', function () {
        expect($this->extractor->canHandle('https://www.ocado.com/search/?entry=dog%20food'))
            ->toBeTrue();
    });

    test('returns true for category browse with pagination', function () {
        expect($this->extractor->canHandle('https://www.ocado.com/browse/pets-20974/dog-111797?page=2'))
            ->toBeTrue();
    });

    test('returns false for product pages', function () {
        expect($this->extractor->canHandle('https://www.ocado.com/products/pedigree-dog-food-1234567'))
            ->toBeFalse();
    });

    test('returns false for homepage', function () {
        expect($this->extractor->canHandle('https://www.ocado.com/'))
            ->toBeFalse();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/browse/pets'))
            ->toBeFalse();
    });
});

describe('product URL extraction', function () {
    test('extracts product URLs from category page', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-category-page.html');
        $url = 'https://www.ocado.com/browse/pets-20974/dog-111797/dog-food-111800';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->not->toBeEmpty()
            ->and(count($productUrls))->toBeGreaterThanOrEqual(4);
    });

    test('extracts product URLs from anchor tags', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/products/pedigree-adult-dry-dog-food-chicken-234567">Product 1</a>
            <a href="/products/royal-canin-mini-adult-2kg-567890">Product 2</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.ocado.com/browse/pets-20974/dog-111797';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->toHaveCount(2);
    });

    test('normalizes relative URLs to absolute', function () {
        $html = '<html><body><a href="/products/test-product-1234567">Product</a></body></html>';
        $url = 'https://www.ocado.com/browse/pets-20974';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->url)->toStartWith('https://www.ocado.com');
    });

    test('deduplicates product URLs', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/products/same-product-1234567">Product</a>
            <a href="/products/same-product-1234567">Same Product</a>
            <a href="https://www.ocado.com/products/same-product-1234567">Same Again</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.ocado.com/browse/pets-20974';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->toHaveCount(1);
    });

    test('includes retailer information in DTO', function () {
        $html = '<html><body><a href="/products/test-1234567">Product</a></body></html>';
        $url = 'https://www.ocado.com/browse/pets-20974';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->retailer)->toBe('ocado');
    });

    test('includes metadata with discovery information', function () {
        $html = '<html><body><a href="/products/test-1234567">Product</a></body></html>';
        $url = 'https://www.ocado.com/browse/pets-20974';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->metadata)->toHaveKey('discovered_from')
            ->and($productUrls[0]->metadata['discovered_from'])->toBe($url);
    });
});

describe('category extraction', function () {
    test('extracts dog-food category from URL', function () {
        $html = '<html><body><a href="/products/test-1234567">Product</a></body></html>';
        $url = 'https://www.ocado.com/browse/pets-20974/dog-111797/dog-food-111800';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->category)->toBe('dog-food');
    });

    test('extracts dog category from URL when no specific category', function () {
        $html = '<html><body><a href="/products/test-1234567">Product</a></body></html>';
        $url = 'https://www.ocado.com/browse/pets-20974/dog-111797';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->category)->toBe('dog');
    });

    test('extracts cat-food category from URL', function () {
        $html = '<html><body><a href="/products/test-1234567">Product</a></body></html>';
        $url = 'https://www.ocado.com/browse/pets-20974/cat-111798/cat-food-111801';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);
        $productUrls = array_values($productUrls);

        expect($productUrls[0]->category)->toBe('cat-food');
    });
});

describe('pagination extraction', function () {
    test('extracts next page URL from pagination', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-category-page.html');
        $url = 'https://www.ocado.com/browse/pets-20974/dog-111797/dog-food-111800';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $paginatedUrls = array_filter($results, fn ($item) => $item instanceof PaginatedUrl);

        expect($paginatedUrls)->not->toBeEmpty();
    });

    test('includes page number in paginated URL', function () {
        $html = '<html><body><a href="/products/test-1234567">Product</a><a href="/browse/dog?page=2" rel="next">Next</a></body></html>';
        $url = 'https://www.ocado.com/browse/pets-20974/dog-111797';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $paginatedUrls = array_filter($results, fn ($item) => $item instanceof PaginatedUrl);
        $paginatedUrls = array_values($paginatedUrls);

        expect($paginatedUrls)->toHaveCount(1)
            ->and($paginatedUrls[0]->page)->toBe(2)
            ->and($paginatedUrls[0]->retailer)->toBe('ocado');
    });

    test('extracts current page number from URL', function () {
        $html = '<html><body><a href="/products/test-1234567">Product</a><a href="/browse/dog?page=4" rel="next">Next</a></body></html>';
        $url = 'https://www.ocado.com/browse/pets-20974/dog-111797?page=3';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $paginatedUrls = array_filter($results, fn ($item) => $item instanceof PaginatedUrl);
        $paginatedUrls = array_values($paginatedUrls);

        expect($paginatedUrls[0]->page)->toBe(4);
    });
});

describe('blocked page detection', function () {
    test('returns empty for blocked/captcha page', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-blocked-page.html');
        $url = 'https://www.ocado.com/browse/pets-20974';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('detects robot check from page content', function () {
        $html = '<html><body><p>robot check required</p></body></html>';
        $url = 'https://www.ocado.com/browse/pets-20974';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });
});

describe('edge cases', function () {
    test('handles empty HTML gracefully', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.ocado.com/browse/pets-20974';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles malformed links gracefully', function () {
        $html = '<html><body><a href="">Empty Link</a><a>No href</a></body></html>';
        $url = 'https://www.ocado.com/browse/pets-20974';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('skips non-product links', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/browse/pets-20974">Category Link</a>
            <a href="/search/?entry=dog">Search Link</a>
            <a href="/about-us">About Link</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.ocado.com/browse/pets-20974';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->toBeEmpty();
    });

    test('validates product URL format', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <a href="/products/valid-product-with-sku-1234567">Valid Product</a>
            <a href="/products/no-sku-number">Invalid Product</a>
            <a href="/products/">Empty Product</a>
        </body>
        </html>
        HTML;
        $url = 'https://www.ocado.com/browse/pets-20974';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $productUrls = array_filter($results, fn ($item) => $item instanceof ProductListingUrl);

        expect($productUrls)->toHaveCount(1);
    });
});
