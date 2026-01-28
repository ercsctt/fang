<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\BM\BMProductDetailsExtractor;
use App\Crawler\Services\CategoryExtractor;

beforeEach(function () {
    $this->extractor = new BMProductDetailsExtractor(
        app(CategoryExtractor::class)
    );
});

describe('canHandle', function () {
    test('returns true for bmstores.co.uk product URLs with /product/ pattern', function () {
        expect($this->extractor->canHandle('https://www.bmstores.co.uk/product/pedigree-dog-food-123456'))
            ->toBeTrue();
    });

    test('returns true for bmstores.co.uk product URLs with /p/{number} pattern', function () {
        expect($this->extractor->canHandle('https://www.bmstores.co.uk/p/123456'))
            ->toBeTrue();
    });

    test('returns true for bmstores.co.uk product URLs with /pd/{slug} pattern', function () {
        expect($this->extractor->canHandle('https://www.bmstores.co.uk/pd/pedigree-dog-food'))
            ->toBeTrue();
    });

    test('returns false for bmstores.co.uk non-product URLs', function () {
        expect($this->extractor->canHandle('https://www.bmstores.co.uk/pets/dog-food'))
            ->toBeFalse();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/product/123456'))
            ->toBeFalse();
    });
});

describe('price extraction', function () {
    it('parses pound format correctly', function (string $priceText, int $expectedPence) {
        expect($this->extractor->parsePriceToPence($priceText))->toBe($expectedPence);
    })->with([
        'simple decimal' => ['£12.99', 1299],
        'without currency symbol' => ['12.99', 1299],
        'with spaces' => [' £12.99 ', 1299],
        'high value' => ['£123.45', 12345],
        'single digit pence' => ['£12.9', 1290],
        'comma as decimal' => ['12,99', 1299],
        'with comma thousands' => ['£1,234.56', 123456],
    ]);

    it('parses pence format correctly', function (string $priceText, int $expectedPence) {
        expect($this->extractor->parsePriceToPence($priceText))->toBe($expectedPence);
    })->with([
        'simple pence' => ['99p', 99],
        'large pence' => ['1299p', 1299],
        'small pence' => ['50p', 50],
    ]);

    it('handles whole numbers correctly', function (string $priceText, int $expectedPence) {
        expect($this->extractor->parsePriceToPence($priceText))->toBe($expectedPence);
    })->with([
        'small number as pounds' => ['12', 1200],
        'larger number as pence' => ['1299', 1299],
        'boundary value (99)' => ['99', 9900],
        'boundary value (100)' => ['100', 100],
    ]);

    test('returns null for invalid price strings', function () {
        expect($this->extractor->parsePriceToPence(''))->toBeNull()
            ->and($this->extractor->parsePriceToPence('free'))->toBeNull()
            ->and($this->extractor->parsePriceToPence('N/A'))->toBeNull();
    });

    test('extracts price from product page fixture', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-product-page.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->pricePence)->toBe(2499);
    });

    test('extracts original price from product page fixture', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-product-page.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->originalPricePence)->toBe(2999);
    });
});

describe('weight/quantity parsing', function () {
    it('parses weight correctly', function (string $text, int $expectedGrams) {
        expect($this->extractor->parseWeight($text))->toBe($expectedGrams);
    })->with([
        'kilograms' => ['2.5kg', 2500],
        'kilograms with space' => ['2.5 kg', 2500],
        'grams' => ['400g', 400],
        'grams with space' => ['400 g', 400],
        'millilitres' => ['500ml', 500],
        'litres' => ['1l', 1000],
        'litres full word' => ['1.5 litre', 1500],
        'litres plural' => ['2 litres', 2000],
        'ltr abbreviation' => ['1ltr', 1000],
        'decimal with comma' => ['2,5kg', 2500],
        'in title context' => ['Pedigree Adult Dog Food 12kg', 12000],
        'pack format' => ['12 x 400g', 400],
    ]);

    test('returns null for text without weight', function () {
        expect($this->extractor->parseWeight('Pedigree Adult Dog Food'))->toBeNull()
            ->and($this->extractor->parseWeight(''))->toBeNull();
    });

    test('extracts weight from product page fixture', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-product-page.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->weightGrams)->toBe(12000);
    });
});

describe('brand extraction', function () {
    test('extracts brand from product page fixture', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-product-page.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Pedigree');
    });

    test('extracts known brand from title', function () {
        $html = '<html><body><h1>Royal Canin Medium Adult Dog Food 4kg</h1><div class="price">£29.99</div></body></html>';
        $url = 'https://www.bmstores.co.uk/product/royal-canin-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Royal Canin');
    });

    test('extracts brand from title when not in list', function () {
        $html = '<html><body><h1>NewBrand Premium Dog Food 2kg</h1><div class="price">£19.99</div></body></html>';
        $url = 'https://www.bmstores.co.uk/product/newbrand-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('NewBrand');
    });
});

describe('stock status detection', function () {
    test('detects in stock status from product page fixture', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-product-page.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeTrue();
    });

    test('detects out of stock from product page', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-product-page-out-of-stock.html');
        $url = 'https://www.bmstores.co.uk/product/royal-canin-234567';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeFalse();
    });

    test('detects out of stock via data attribute', function () {
        $html = '<html><body><div data-stock-status="out"><h1>Test Product 500g</h1><div class="price">£5.99</div></div></body></html>';
        $url = 'https://www.bmstores.co.uk/product/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeFalse();
    });

    test('defaults to in stock when status cannot be determined', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-product-page-minimal.html');
        $url = 'https://www.bmstores.co.uk/product/generic-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeTrue();
    });
});

describe('external ID extraction', function () {
    it('extracts external ID from various URL patterns', function (string $url, string $expectedId) {
        expect($this->extractor->extractExternalId($url))->toBe($expectedId);
    })->with([
        'product with number suffix' => ['https://www.bmstores.co.uk/product/pedigree-dog-food-123456', '123456'],
        '/p/ pattern' => ['https://www.bmstores.co.uk/p/789012', '789012'],
        '/pd/ pattern' => ['https://www.bmstores.co.uk/pd/pedigree-adult', 'pedigree-adult'],
        'query string pattern' => ['https://www.bmstores.co.uk/product?product_id=abc123', 'abc123'],
    ]);

    test('extracts external ID from data attribute', function () {
        $html = '<html><body><div data-product-id="SKU123"><h1>Test Product</h1></div></body></html>';
        $url = 'https://www.bmstores.co.uk/product/test';

        expect($this->extractor->extractExternalId($url, new \Symfony\Component\DomCrawler\Crawler($html)))->toBe('SKU123');
    });

    test('returns null when no ID found', function () {
        expect($this->extractor->extractExternalId('https://www.bmstores.co.uk/about'))->toBeNull();
    });
});

describe('extraction from fixtures', function () {
    test('extracts complete product details from full fixture', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-product-page.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0])->toBeInstanceOf(ProductDetails::class)
            ->and($results[0]->title)->toBe('Pedigree Adult Dry Dog Food Chicken 12kg')
            ->and($results[0]->brand)->toBe('Pedigree')
            ->and($results[0]->pricePence)->toBe(2499)
            ->and($results[0]->originalPricePence)->toBe(2999)
            ->and($results[0]->currency)->toBe('GBP')
            ->and($results[0]->weightGrams)->toBe(12000)
            ->and($results[0]->inStock)->toBeTrue()
            ->and($results[0]->images)->toBeArray()
            ->and($results[0]->images)->toContain('https://images.bmstores.co.uk/product/123456-main.jpg')
            ->and($results[0]->ingredients)->toContain('Cereals');
    });

    test('extracts from minimal fixture', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-product-page-minimal.html');
        $url = 'https://www.bmstores.co.uk/product/generic-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Generic Dog Treats 500g')
            ->and($results[0]->pricePence)->toBe(599)
            ->and($results[0]->weightGrams)->toBe(500);
    });

    test('includes metadata with extraction info', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-product-page.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('source_url')
            ->and($results[0]->metadata['source_url'])->toBe($url)
            ->and($results[0]->metadata)->toHaveKey('extracted_at')
            ->and($results[0]->metadata)->toHaveKey('retailer')
            ->and($results[0]->metadata['retailer'])->toBe('bm');
    });
});

describe('image extraction', function () {
    test('extracts images from src attribute', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-product-page.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toContain('https://images.bmstores.co.uk/product/123456-main.jpg')
            ->toContain('https://images.bmstores.co.uk/product/123456-side.jpg');
    });

    test('extracts images from data-src attribute', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-product-page-out-of-stock.html');
        $url = 'https://www.bmstores.co.uk/product/royal-canin-234567';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toContain('https://images.bmstores.co.uk/product/234567-main.jpg');
    });

    test('deduplicates images', function () {
        $html = '
            <html><body>
                <h1>Test Product 500g</h1>
                <div class="price">£5.99</div>
                <div class="product-image">
                    <img src="https://example.com/img.jpg">
                    <img src="https://example.com/img.jpg">
                </div>
            </body></html>
        ';
        $url = 'https://www.bmstores.co.uk/product/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toHaveCount(1);
    });

    test('skips placeholder images', function () {
        $html = '
            <html><body>
                <h1>Test Product 500g</h1>
                <div class="price">£5.99</div>
                <div class="product-image">
                    <img src="https://example.com/placeholder.jpg">
                    <img src="https://example.com/loading.gif">
                    <img src="https://example.com/real-image.jpg">
                </div>
            </body></html>
        ';
        $url = 'https://www.bmstores.co.uk/product/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toHaveCount(1)
            ->and($results[0]->images[0])->toBe('https://example.com/real-image.jpg');
    });
});

describe('description extraction', function () {
    test('extracts description from product page fixture', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-product-page.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->description)->toContain('Complete and balanced nutrition');
    });

    test('returns null when no description found', function () {
        $html = file_get_contents(__DIR__.'/../../../Fixtures/bm-product-page-minimal.html');
        $url = 'https://www.bmstores.co.uk/product/generic-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->description)->toBeNull();
    });
});

describe('edge cases', function () {
    test('handles missing title gracefully', function () {
        $html = '<html><body><div class="price">£5.99</div></body></html>';
        $url = 'https://www.bmstores.co.uk/product/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Unknown Product');
    });

    test('handles missing price gracefully', function () {
        $html = '<html><body><h1>Test Product 500g</h1></body></html>';
        $url = 'https://www.bmstores.co.uk/product/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->pricePence)->toBe(0);
    });

    test('handles malformed HTML gracefully', function () {
        $html = '<html><body><h1>Test Product<div class="price">£5.99</div></body>';
        $url = 'https://www.bmstores.co.uk/product/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });
});
