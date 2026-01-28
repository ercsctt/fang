<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\Sainsburys\SainsburysProductDetailsExtractor;
use App\Crawler\Services\CategoryExtractor;

beforeEach(function () {
    $this->extractor = new SainsburysProductDetailsExtractor(
        app(CategoryExtractor::class)
    );
});

describe('canHandle', function () {
    test('returns true for gol-ui product URLs', function () {
        expect($this->extractor->canHandle('https://www.sainsburys.co.uk/gol-ui/product/test-product--123456'))
            ->toBeTrue();
    });

    test('returns true for product slug URLs', function () {
        expect($this->extractor->canHandle('https://www.sainsburys.co.uk/product/test-product-123456'))
            ->toBeTrue();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/groceries/en-GB/products/123456'))
            ->toBeFalse();
    });
});

describe('JSON-LD extraction', function () {
    test('extracts product details from JSON-LD structured data', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Sainsbury's Complete Dog Food 2kg",
                "description": "Complete dog food",
                "brand": {
                    "@type": "Brand",
                    "name": "Sainsbury's"
                },
                "offers": {
                    "@type": "Offer",
                    "price": "3.50",
                    "availability": "https://schema.org/InStock"
                },
                "sku": "123456"
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/test-product--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0])->toBeInstanceOf(ProductDetails::class)
            ->and($results[0]->title)->toBe("Sainsbury's Complete Dog Food 2kg")
            ->and($results[0]->pricePence)->toBe(350)
            ->and($results[0]->inStock)->toBeTrue();
    });
});

describe('DOM extraction fallback', function () {
    test('extracts title and price from DOM when JSON-LD unavailable', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1 data-test-id="pd-product-title">Bakers Adult Dog Food Beef 5kg</h1>
            <span data-test-id="pd-product-price">£10.00</span>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/product/test-product-654321';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Bakers Adult Dog Food Beef 5kg')
            ->and($results[0]->pricePence)->toBe(1000);
    });
});

describe('metadata extraction', function () {
    test('extracts nectar price and multibuy offer', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1 data-test-id="pd-product-title">Test Product</h1>
            <span data-test-id="pd-product-price">£4.00</span>
            <span data-test-id="pd-nectar-price">£3.50</span>
            <div data-test-id="pd-multibuy">2 for £6</div>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/test-product--999999';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $metadata = $results[0]->metadata;

        expect($metadata['nectar_price_pence'])->toBe(350)
            ->and($metadata['multi_buy_offer']['quantity'])->toBe(2)
            ->and($metadata['multi_buy_offer']['price'])->toBe(600);
    });
});

describe('external ID extraction', function () {
    test('extracts product ID from gol-ui URL', function () {
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/test-product--123456';

        expect($this->extractor->extractExternalId($url))->toBe('123456');
    });
});
