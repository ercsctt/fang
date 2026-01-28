<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\Amazon\AmazonProductDetailsExtractor;
use App\Services\ProductNormalizer;

beforeEach(function () {
    $this->extractor = new AmazonProductDetailsExtractor;
});

describe('canHandle', function () {
    test('returns true for amazon.co.uk /dp/ product URLs', function () {
        expect($this->extractor->canHandle('https://www.amazon.co.uk/dp/B08L5WRMZJ'))
            ->toBeTrue();
    });

    test('returns true for amazon.co.uk /dp/ URLs with trailing path', function () {
        expect($this->extractor->canHandle('https://www.amazon.co.uk/dp/B08L5WRMZJ/ref=sr_1_1'))
            ->toBeTrue();
    });

    test('returns true for amazon.co.uk /gp/product/ URLs', function () {
        expect($this->extractor->canHandle('https://www.amazon.co.uk/gp/product/B07N4CXL1Y'))
            ->toBeTrue();
    });

    test('returns false for amazon.co.uk search pages', function () {
        expect($this->extractor->canHandle('https://www.amazon.co.uk/s?k=dog+food'))
            ->toBeFalse();
    });

    test('returns false for amazon.com (non-UK)', function () {
        expect($this->extractor->canHandle('https://www.amazon.com/dp/B08L5WRMZJ'))
            ->toBeFalse();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.ebay.co.uk/dp/B08L5WRMZJ'))
            ->toBeFalse();
    });
});

describe('ASIN extraction', function () {
    it('extracts ASIN from /dp/ URLs', function (string $url, string $expectedAsin) {
        expect($this->extractor->extractAsin($url))->toBe($expectedAsin);
    })->with([
        'simple dp' => ['https://www.amazon.co.uk/dp/B08L5WRMZJ', 'B08L5WRMZJ'],
        'dp with ref' => ['https://www.amazon.co.uk/dp/B08L5WRMZJ/ref=sr_1_1', 'B08L5WRMZJ'],
        'dp with query' => ['https://www.amazon.co.uk/dp/B08L5WRMZJ?psc=1', 'B08L5WRMZJ'],
    ]);

    it('extracts ASIN from /gp/product/ URLs', function (string $url, string $expectedAsin) {
        expect($this->extractor->extractAsin($url))->toBe($expectedAsin);
    })->with([
        'simple gp' => ['https://www.amazon.co.uk/gp/product/B07N4CXL1Y', 'B07N4CXL1Y'],
        'gp with ref' => ['https://www.amazon.co.uk/gp/product/B07N4CXL1Y/ref=cm_cr_dp_d_show_all_btm', 'B07N4CXL1Y'],
    ]);

    test('uppercases ASIN', function () {
        expect($this->extractor->extractAsin('https://www.amazon.co.uk/dp/b08l5wrmzj'))->toBe('B08L5WRMZJ');
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
    ]);

    it('parses pence format correctly', function (string $priceText, int $expectedPence) {
        expect($this->extractor->parsePriceToPence($priceText))->toBe($expectedPence);
    })->with([
        'simple pence' => ['99p', 99],
        'large pence' => ['1299p', 1299],
    ]);

    test('returns null for invalid price strings', function () {
        expect($this->extractor->parsePriceToPence(''))->toBeNull()
            ->and($this->extractor->parsePriceToPence('free'))->toBeNull()
            ->and($this->extractor->parsePriceToPence('N/A'))->toBeNull();
    });
});

describe('weight parsing', function () {
    it('parses weight correctly', function (string $text, int $expectedGrams) {
        expect(app(ProductNormalizer::class)->parseWeight($text))->toBe($expectedGrams);
    })->with([
        'kilograms' => ['2.5kg', 2500],
        'kilograms with space' => ['2.5 kg', 2500],
        'grams' => ['400g', 400],
        'pounds' => ['5 lb', 2270],
        'pounds plural' => ['10 lbs', 4540],
        'ounces' => ['16 oz', 448],
        'in title context' => ['Pedigree Adult Dog Food 12kg', 12000],
    ]);

    test('returns null for text without weight', function () {
        expect(app(ProductNormalizer::class)->parseWeight('Pedigree Adult Dog Food'))->toBeNull();
    });
});

describe('extraction from HTML', function () {
    test('extracts title from #productTitle', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <span id="productTitle">Pedigree Adult Dry Dog Food Chicken 12kg</span>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Pedigree Adult Dry Dog Food Chicken 12kg');
    });

    test('extracts price from Amazon price selectors', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <span id="productTitle">Test Product</span>
            <span class="priceToPay">
                <span class="a-offscreen">£24.99</span>
            </span>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->pricePence)->toBe(2499);
    });

    test('extracts brand from byline', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <span id="productTitle">Test Product</span>
            <a id="bylineInfo">Visit the Pedigree Store</a>
            <span class="priceToPay"><span class="a-offscreen">£24.99</span></span>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Pedigree');
    });

    test('extracts ASIN as external ID', function () {
        $html = '<html><body><span id="productTitle">Test</span><span class="priceToPay"><span class="a-offscreen">£5</span></span></body></html>';
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('B08L5WRMZJ');
    });

    test('detects stock status from availability text', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <span id="productTitle">Test Product</span>
            <span class="priceToPay"><span class="a-offscreen">£24.99</span></span>
            <div id="availability"><span>In Stock</span></div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeTrue();
    });

    test('detects out of stock from availability text', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <span id="productTitle">Test Product</span>
            <div id="availability"><span>Currently unavailable</span></div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeFalse();
    });

    test('includes metadata with Amazon-specific fields', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <span id="productTitle">Test Product</span>
            <span class="priceToPay"><span class="a-offscreen">£24.99</span></span>
            <span id="prime-badge"></span>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('source_url')
            ->and($results[0]->metadata['source_url'])->toBe($url)
            ->and($results[0]->metadata)->toHaveKey('retailer')
            ->and($results[0]->metadata['retailer'])->toBe('amazon-uk')
            ->and($results[0]->metadata)->toHaveKey('asin')
            ->and($results[0]->metadata['asin'])->toBe('B08L5WRMZJ')
            ->and($results[0]->metadata)->toHaveKey('prime_eligible')
            ->and($results[0]->metadata['prime_eligible'])->toBeTrue();
    });
});

describe('CAPTCHA detection', function () {
    test('yields nothing when CAPTCHA detected', function () {
        $html = <<<'HTML'
        <html>
        <head><title>Sorry! Something went wrong!</title></head>
        <body>
            <div>To discuss automated access to Amazon data please contact api-services-support@amazon.com</div>
            <div class="captcha">Enter the characters you see below</div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('yields nothing when robot check detected', function () {
        $html = <<<'HTML'
        <html>
        <head><title>Robot Check</title></head>
        <body>
            <h4>Enter the characters you see below</h4>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });
});

describe('JSON-LD extraction', function () {
    test('extracts data from JSON-LD when available', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Pedigree Adult Dry Dog Food 12kg",
                "brand": {"@type": "Brand", "name": "Pedigree"},
                "offers": {
                    "@type": "Offer",
                    "price": 24.99,
                    "priceCurrency": "GBP"
                }
            }
            </script>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Pedigree Adult Dry Dog Food 12kg')
            ->and($results[0]->brand)->toBe('Pedigree')
            ->and($results[0]->pricePence)->toBe(2499);
    });
});

describe('edge cases', function () {
    test('handles missing title gracefully', function () {
        $html = '<html><body><span class="priceToPay"><span class="a-offscreen">£5.99</span></span></body></html>';
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Unknown Product');
    });

    test('handles missing price gracefully', function () {
        $html = '<html><body><span id="productTitle">Test Product</span></body></html>';
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->pricePence)->toBe(0);
    });

    test('handles malformed HTML gracefully', function () {
        $html = '<html><body><span id="productTitle">Test<div class="priceToPay"><span class="a-offscreen">£5.99</span></div></body>';
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });

    test('yields ProductDetails DTO', function () {
        $html = '<html><body><span id="productTitle">Test</span></body></html>';
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0])->toBeInstanceOf(ProductDetails::class);
    });
});
