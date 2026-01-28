<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\PetsAtHome\PAHProductDetailsExtractor;
use App\Crawler\Services\CategoryExtractor;

beforeEach(function () {
    $this->extractor = new PAHProductDetailsExtractor(
        app(CategoryExtractor::class)
    );
});

describe('canHandle', function () {
    test('returns true for petsathome.com product URLs with product code', function () {
        expect($this->extractor->canHandle('https://www.petsathome.com/product/wainwrights-adult-chicken/P71341'))
            ->toBeTrue();
    });

    test('returns true for product URLs with alphanumeric product code', function () {
        expect($this->extractor->canHandle('https://www.petsathome.com/product/royal-canin-medium-adult/7136893P'))
            ->toBeTrue();
    });

    test('returns false for category pages', function () {
        expect($this->extractor->canHandle('https://www.petsathome.com/shop/en/pets/dog/dog-food'))
            ->toBeFalse();
    });

    test('returns false for search pages', function () {
        expect($this->extractor->canHandle('https://www.petsathome.com/search?q=dog%20food'))
            ->toBeFalse();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/product/test/123456'))
            ->toBeFalse();
    });
});

describe('product details extraction', function () {
    test('extracts product details from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/petsathome-product-page.html');
        $url = 'https://www.petsathome.com/product/wainwrights-adult-complete-dry-dog-food-chicken-2kg/P71341';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0])->toBeInstanceOf(ProductDetails::class)
            ->and($results[0]->title)->toBe("Wainwright's Adult Complete Dry Dog Food Chicken 2kg")
            ->and($results[0]->description)->toContain('complete and balanced dry food')
            ->and($results[0]->brand)->toBe("Wainwright's")
            ->and($results[0]->pricePence)->toBe(800)
            ->and($results[0]->inStock)->toBeTrue();
    });

    test('extracts product details from DOM selectors', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1 data-testid="product-title">Royal Canin Medium Adult 4kg</h1>
            <div data-testid="product-price" data-price="26.00">£26.00</div>
            <div data-testid="product-description">Premium nutrition for medium dogs</div>
            <div data-testid="product-brand">Royal Canin</div>
        </body>
        </html>
        HTML;
        $url = 'https://www.petsathome.com/product/royal-canin-medium-adult/7136893P';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Royal Canin Medium Adult 4kg')
            ->and($results[0]->pricePence)->toBe(2600)
            ->and($results[0]->description)->toBe('Premium nutrition for medium dogs')
            ->and($results[0]->brand)->toBe('Royal Canin');
    });

    test('extracts external ID from URL', function () {
        $html = '<html><body><h1>Product</h1></body></html>';
        $url = 'https://www.petsathome.com/product/test-product-name/P98765';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('P98765');
    });

    test('extracts external ID with numeric-letter format', function () {
        $html = '<html><body><h1>Product</h1></body></html>';
        $url = 'https://www.petsathome.com/product/test-product/7136893P';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('7136893P');
    });

    test('extracts weight from product title', function () {
        $html = '<html><body><h1 data-testid="product-title">Harringtons Dog Food 10kg</h1></body></html>';
        $url = 'https://www.petsathome.com/product/harringtons-10kg/P12345';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->weightGrams)->toBe(10000);
    });

    test('yields ProductDetails DTO with correct retailer metadata', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/petsathome-product-page.html');
        $url = 'https://www.petsathome.com/product/wainwrights-adult-complete-dry-dog-food-chicken-2kg/P71341';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['retailer'])->toBe('pets-at-home')
            ->and($results[0]->metadata['source_url'])->toBe($url);
    });

    test('includes rating information in metadata', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/petsathome-product-page.html');
        $url = 'https://www.petsathome.com/product/wainwrights-adult-complete-dry-dog-food-chicken-2kg/P71341';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['rating_value'])->toBe('4.7')
            ->and($results[0]->metadata['review_count'])->toBe('892');
    });
});

describe('out of stock detection', function () {
    test('detects out of stock from JSON-LD availability', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/petsathome-product-page-out-of-stock.html');
        $url = 'https://www.petsathome.com/product/burns-adult/P12345';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeFalse();
    });

    test('detects out of stock from DOM indicator', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1 data-testid="product-title">Sold Out Product</h1>
            <div class="out-of-stock" data-testid="out-of-stock">Out of stock</div>
        </body>
        </html>
        HTML;
        $url = 'https://www.petsathome.com/product/test/P12345';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeFalse();
    });

    test('defaults to in stock when add to basket button present', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1 data-testid="product-title">Available Product</h1>
            <button class="add-to-basket">Add to basket</button>
        </body>
        </html>
        HTML;
        $url = 'https://www.petsathome.com/product/test/P12345';

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

    test('uses first price from multiple offers', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/petsathome-product-page.html');
        $url = 'https://www.petsathome.com/product/wainwrights-adult-complete-dry-dog-food-chicken-2kg/P71341';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->pricePence)->toBe(800);
    });
});

describe('weight parsing', function () {
    test('parses weight in kilograms', function () {
        expect($this->extractor->parseWeight('2.5kg'))->toBe(2500)
            ->and($this->extractor->parseWeight('12kg'))->toBe(12000);
    });

    test('parses weight in grams', function () {
        expect($this->extractor->parseWeight('500g'))->toBe(500)
            ->and($this->extractor->parseWeight('800g'))->toBe(800);
    });

    test('parses weight from product title', function () {
        expect($this->extractor->parseWeight('Pedigree Adult Dog Food 2.6kg'))->toBe(2600)
            ->and($this->extractor->parseWeight('Royal Canin Medium Adult 15kg'))->toBe(15000);
    });

    test('parses volume in litres', function () {
        expect($this->extractor->parseWeight('1l'))->toBe(1000)
            ->and($this->extractor->parseWeight('500ml'))->toBe(500)
            ->and($this->extractor->parseWeight('2 litres'))->toBe(2000);
    });

    test('returns null for text without weight', function () {
        expect($this->extractor->parseWeight('Dog Food Premium'))->toBeNull();
    });
});

describe('brand extraction', function () {
    test('extracts brand from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/petsathome-product-page.html');
        $url = 'https://www.petsathome.com/product/wainwrights-adult-complete-dry-dog-food-chicken-2kg/P71341';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe("Wainwright's");
    });

    test('extracts known brands from title', function () {
        $html = '<html><body><h1 data-testid="product-title">Pedigree Complete Adult Dog Food 2.6kg</h1></body></html>';
        $url = 'https://www.petsathome.com/product/pedigree/P12345';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Pedigree');
    });

    test('extracts Burns brand from title', function () {
        $html = '<html><body><h1 data-testid="product-title">Burns Adult Original Chicken & Brown Rice 12kg</h1></body></html>';
        $url = 'https://www.petsathome.com/product/burns/P12345';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Burns');
    });

    test('extracts brand from DOM selector', function () {
        $html = '<html><body><h1>Product</h1><div data-testid="product-brand">Royal Canin</div></body></html>';
        $url = 'https://www.petsathome.com/product/test/P12345';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Royal Canin');
    });
});

describe('image extraction', function () {
    test('extracts images from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/petsathome-product-page.html');
        $url = 'https://www.petsathome.com/product/wainwrights-adult-complete-dry-dog-food-chicken-2kg/P71341';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toBeArray()
            ->and($results[0]->images)->not->toBeEmpty();
    });

    test('extracts images from DOM when no JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1>Product</h1>
            <div class="product-image">
                <img src="https://media.petsathome.com/image1.jpg" />
                <img src="https://media.petsathome.com/image2.jpg" />
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.petsathome.com/product/test/P12345';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toHaveCount(2);
    });

    test('skips placeholder images', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1>Product</h1>
            <div class="product-image">
                <img src="https://media.petsathome.com/real-image.jpg" />
                <img src="https://media.petsathome.com/placeholder.jpg" />
                <img src="https://media.petsathome.com/loading.gif" />
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.petsathome.com/product/test/P12345';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toHaveCount(1);
    });
});

describe('ingredients extraction', function () {
    test('extracts ingredients from product page', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/petsathome-product-page.html');
        $url = 'https://www.petsathome.com/product/wainwrights-adult-complete-dry-dog-food-chicken-2kg/P71341';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->ingredients)->toContain('Chicken');
    });
});

describe('category extraction', function () {
    test('extracts category from breadcrumbs', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/petsathome-product-page.html');
        $url = 'https://www.petsathome.com/product/wainwrights-adult-complete-dry-dog-food-chicken-2kg/P71341';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        // Breadcrumbs: Home > Dog > Dog Food > Dry Dog Food, extractCategory picks index (count-2)=2 which is "Dog Food"
        expect($results[0]->category)->toBe('Dog Food');
    });

    test('extracts category from URL when no breadcrumbs', function () {
        $html = '<html><body><h1>Test Product</h1></body></html>';
        $url = 'https://www.petsathome.com/product/dog-food/test/P12345';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        // extractCategory falls back to URL parsing: matches /dog/ with /food/ and returns "dog-food"
        expect($results[0]->category)->toBe('dog-food');
    });

    test('extracts external ID from URL with product code', function () {
        $id = $this->extractor->extractExternalId('https://www.petsathome.com/product/test-product/P12345');
        expect($id)->toBe('P12345');
    });
});

describe('edge cases', function () {
    test('handles empty HTML gracefully', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.petsathome.com/product/test/P12345';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Unknown Product')
            ->and($results[0]->pricePence)->toBe(0);
    });

    test('handles malformed JSON-LD gracefully', function () {
        $html = '<html><head><script type="application/ld+json">{ invalid json }</script></head><body><h1 data-testid="product-title">Fallback Title</h1></body></html>';
        $url = 'https://www.petsathome.com/product/test/P12345';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Fallback Title');
    });

    test('handles missing price gracefully', function () {
        $html = '<html><body><h1 data-testid="product-title">Product Without Price</h1></body></html>';
        $url = 'https://www.petsathome.com/product/test/P12345';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->pricePence)->toBe(0);
    });

    test('extracts quantity from title with pack format', function () {
        $html = '<html><body><h1 data-testid="product-title">Pedigree Pouches 12 x 100g</h1></body></html>';
        $url = 'https://www.petsathome.com/product/pedigree-pouches/P12345';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->quantity)->toBe(12);
    });
});
