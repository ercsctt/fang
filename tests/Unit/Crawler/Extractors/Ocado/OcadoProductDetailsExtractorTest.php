<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\Ocado\OcadoProductDetailsExtractor;
use App\Crawler\Services\CategoryExtractor;

beforeEach(function () {
    $this->extractor = new OcadoProductDetailsExtractor(
        app(CategoryExtractor::class)
    );
});

describe('canHandle', function () {
    test('returns true for ocado.com product URLs with slug and SKU', function () {
        expect($this->extractor->canHandle('https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890'))
            ->toBeTrue();
    });

    test('returns true for product URLs without www prefix', function () {
        expect($this->extractor->canHandle('https://ocado.com/products/pedigree-chicken-123456'))
            ->toBeTrue();
    });

    test('returns false for browse/category pages', function () {
        expect($this->extractor->canHandle('https://www.ocado.com/browse/pets-20974/dog-111797'))
            ->toBeFalse();
    });

    test('returns false for search pages', function () {
        expect($this->extractor->canHandle('https://www.ocado.com/search?entry=dog%20food'))
            ->toBeFalse();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/products/test-123456'))
            ->toBeFalse();
    });
});

describe('product details extraction', function () {
    test('extracts product details from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0])->toBeInstanceOf(ProductDetails::class)
            ->and($results[0]->title)->toBe('Royal Canin Mini Adult Dog Food 2kg')
            ->and($results[0]->description)->toContain('Complete and balanced nutrition')
            ->and($results[0]->brand)->toBe('Royal Canin')
            ->and($results[0]->pricePence)->toBe(1599)
            ->and($results[0]->inStock)->toBeTrue();
    });

    test('extracts product details from DOM selectors', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1 data-testid="product-title">Pedigree Adult Dry Dog Food Chicken 2.6kg</h1>
            <div data-testid="product-price" data-price="5.50">£5.50</div>
            <div data-testid="product-description">Complete dry food for adult dogs</div>
            <div data-testid="product-brand">Pedigree</div>
        </body>
        </html>
        HTML;
        $url = 'https://www.ocado.com/products/pedigree-adult-dry-dog-food-chicken-234567';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Pedigree Adult Dry Dog Food Chicken 2.6kg')
            ->and($results[0]->pricePence)->toBe(550)
            ->and($results[0]->description)->toBe('Complete dry food for adult dogs')
            ->and($results[0]->brand)->toBe('Pedigree');
    });

    test('extracts external ID from URL', function () {
        $html = '<html><body><h1>Product</h1></body></html>';
        $url = 'https://www.ocado.com/products/test-product-name-here-9876543';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('9876543');
    });

    test('extracts weight from product title', function () {
        $html = '<html><body><h1 data-testid="product-title">Harringtons Dog Food 10kg</h1></body></html>';
        $url = 'https://www.ocado.com/products/harringtons-10kg-1234567';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->weightGrams)->toBe(10000);
    });

    test('yields ProductDetails DTO with correct retailer metadata', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['retailer'])->toBe('ocado')
            ->and($results[0]->metadata['source_url'])->toBe($url);
    });
});

describe('blocked page detection', function () {
    test('returns empty for blocked/captcha page', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-blocked-page.html');
        $url = 'https://www.ocado.com/products/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('detects blocked page from title', function () {
        $html = '<html><head><title>Blocked</title></head><body></body></html>';
        $url = 'https://www.ocado.com/products/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('detects captcha from page content', function () {
        $html = '<html><body><h1>Robot Check</h1><p>Please complete the captcha</p></body></html>';
        $url = 'https://www.ocado.com/products/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });
});

describe('out of stock detection', function () {
    test('detects out of stock from JSON-LD availability', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page-out-of-stock.html');
        $url = 'https://www.ocado.com/products/lilys-kitchen-2345678';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeFalse();
    });

    test('detects out of stock from DOM indicator', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1 data-testid="product-title">Sold Out Product</h1>
            <div class="fop-out-of-stock">Out of stock</div>
        </body>
        </html>
        HTML;
        $url = 'https://www.ocado.com/products/test-1234567';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeFalse();
    });

    test('defaults to in stock when no indicators present', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1 data-testid="product-title">Available Product</h1>
            <button class="add-to-basket">Add</button>
        </body>
        </html>
        HTML;
        $url = 'https://www.ocado.com/products/test-1234567';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeTrue();
    });
});

describe('price parsing', function () {
    test('parses price in pounds format', function () {
        expect($this->extractor->parsePriceToPence('£12.99'))->toBe(1299)
            ->and($this->extractor->parsePriceToPence('£5.50'))->toBe(550)
            ->and($this->extractor->parsePriceToPence('£100.00'))->toBe(10000);
    });

    test('parses price in pence format', function () {
        expect($this->extractor->parsePriceToPence('99p'))->toBe(99)
            ->and($this->extractor->parsePriceToPence('50p'))->toBe(50);
    });

    test('parses price without currency symbol', function () {
        expect($this->extractor->parsePriceToPence('12.99'))->toBe(1299)
            ->and($this->extractor->parsePriceToPence('5.50'))->toBe(550);
    });

    test('handles empty or invalid price', function () {
        expect($this->extractor->parsePriceToPence(''))->toBeNull()
            ->and($this->extractor->parsePriceToPence('invalid'))->toBeNull();
    });

    test('handles price with comma separator', function () {
        expect($this->extractor->parsePriceToPence('1,299.00'))->toBe(129900);
    });
});

describe('weight parsing', function () {
    test('parses weight in kilograms', function () {
        expect($this->extractor->parseWeight('2.5kg'))->toBe(2500)
            ->and($this->extractor->parseWeight('12kg'))->toBe(12000)
            ->and($this->extractor->parseWeight('1 kilogram'))->toBe(1000);
    });

    test('parses weight in grams', function () {
        expect($this->extractor->parseWeight('500g'))->toBe(500)
            ->and($this->extractor->parseWeight('800g'))->toBe(800)
            ->and($this->extractor->parseWeight('250 grams'))->toBe(250);
    });

    test('parses weight from product title', function () {
        expect($this->extractor->parseWeight('Pedigree Adult Dog Food 2.6kg'))->toBe(2600)
            ->and($this->extractor->parseWeight('Royal Canin Medium Adult 15kg'))->toBe(15000);
    });

    test('parses volume in litres', function () {
        expect($this->extractor->parseWeight('1l'))->toBe(1000)
            ->and($this->extractor->parseWeight('500ml'))->toBe(500)
            ->and($this->extractor->parseWeight('2 litres'))->toBe(2000)
            ->and($this->extractor->parseWeight('1.5 ltr'))->toBe(1500);
    });

    test('returns null for text without weight', function () {
        expect($this->extractor->parseWeight('Dog Food Premium'))->toBeNull();
    });

    test('parses weight in pounds', function () {
        expect($this->extractor->parseWeight('5lb'))->toBe(2270)
            ->and($this->extractor->parseWeight('10 lbs'))->toBe(4540)
            ->and($this->extractor->parseWeight('1 pound'))->toBe(454);
    });

    test('parses weight in ounces', function () {
        expect($this->extractor->parseWeight('8oz'))->toBe(224)
            ->and($this->extractor->parseWeight('16 ounces'))->toBe(448);
    });
});

describe('brand extraction', function () {
    test('extracts brand from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Royal Canin');
    });

    test('extracts known brands from title', function () {
        $html = '<html><body><h1 data-testid="product-title">Pedigree Complete Adult Dog Food 2.6kg</h1></body></html>';
        $url = 'https://www.ocado.com/products/pedigree-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Pedigree');
    });

    test('extracts Wainwrights brand from title', function () {
        $html = '<html><body><h1 data-testid="product-title">Wainwright\'s Adult Dog Food Chicken 2kg</h1></body></html>';
        $url = 'https://www.ocado.com/products/wainwrights-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe("Wainwright's");
    });
});

describe('image extraction', function () {
    test('extracts images from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toBeArray()
            ->and($results[0]->images)->not->toBeEmpty();
    });

    test('extracts images from DOM when no JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1>Product</h1>
            <div class="fop-images">
                <img src="https://ocado.com/image1.jpg" />
                <img src="https://ocado.com/image2.jpg" />
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.ocado.com/products/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toHaveCount(2);
    });

    test('skips placeholder images', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1>Product</h1>
            <div class="fop-images">
                <img src="https://ocado.com/real-image.jpg" />
                <img src="https://ocado.com/placeholder.jpg" />
                <img src="https://ocado.com/loading.gif" />
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.ocado.com/products/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toHaveCount(1);
    });
});

describe('ingredients extraction', function () {
    test('extracts ingredients from product page', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->ingredients)->toContain('poultry protein');
    });
});

describe('category extraction', function () {
    test('extracts category from breadcrumbs', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        // Breadcrumbs: Home > Pets > Dog > Dog Food, extractCategory picks index (count-2)=2 which is "Dog"
        expect($results[0]->category)->toBe('Dog');
    });

    test('extracts category from URL fallback', function () {
        $html = '<html><body><h1>Test Product</h1></body></html>';
        // URL pattern /dog-food/ will match dog with food type
        $url = 'https://www.ocado.com/products/dog-food-pedigree-chicken-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        // URL pattern extracts "dog-food" from /dog-food/
        expect($results[0]->category)->toBe('dog-food');
    });

    test('extracts external ID from product URL', function () {
        // The extractExternalId pattern expects: /products/[slug]-[digits]$
        $productId = $this->extractor->extractExternalId('https://www.ocado.com/products/pedigree-chicken-123456');
        expect($productId)->toBe('123456');
    });
});

describe('barcode extraction', function () {
    test('extracts barcode/GTIN from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->barcode)->toBe('3182550793124');
    });
});

describe('edge cases', function () {
    test('handles empty HTML gracefully', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.ocado.com/products/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Unknown Product')
            ->and($results[0]->pricePence)->toBe(0);
    });

    test('handles malformed JSON-LD gracefully', function () {
        $html = '<html><head><script type="application/ld+json">{ invalid json }</script></head><body><h1 data-testid="product-title">Fallback Title</h1></body></html>';
        $url = 'https://www.ocado.com/products/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Fallback Title');
    });

    test('handles missing price gracefully', function () {
        $html = '<html><body><h1 data-testid="product-title">Product Without Price</h1></body></html>';
        $url = 'https://www.ocado.com/products/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->pricePence)->toBe(0);
    });

    test('extracts product ID from DOM attributes', function () {
        $id = $this->extractor->extractExternalId('https://www.ocado.com/products/test-product-name-567890');

        expect($id)->toBe('567890');
    });

    test('extracts quantity from title with pack format', function () {
        $html = '<html><body><h1 data-testid="product-title">Pedigree Pouches 12 x 100g</h1></body></html>';
        $url = 'https://www.ocado.com/products/pedigree-pouches-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->quantity)->toBe(12);
    });
});
