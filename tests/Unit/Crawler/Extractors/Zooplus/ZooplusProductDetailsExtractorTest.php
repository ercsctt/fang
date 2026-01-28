<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\Zooplus\ZooplusProductDetailsExtractor;
use App\Crawler\Services\CategoryExtractor;

beforeEach(function () {
    $this->extractor = new ZooplusProductDetailsExtractor(
        app(CategoryExtractor::class)
    );
});

describe('canHandle', function () {
    test('returns true for zooplus.co.uk product URLs', function () {
        expect($this->extractor->canHandle('https://www.zooplus.co.uk/shop/dogs/dry_dog_food/royal_canin/royal-canin-maxi-adult_123456'))
            ->toBeTrue();
    });

    test('returns true for zooplus.co.uk product URLs with complex slugs', function () {
        expect($this->extractor->canHandle('https://www.zooplus.co.uk/shop/dogs/wet_dog_food/lilys_kitchen/lilys-kitchen-organic-dog-food-400g_987654'))
            ->toBeTrue();
    });

    test('returns false for zooplus category pages', function () {
        expect($this->extractor->canHandle('https://www.zooplus.co.uk/shop/dogs/dry_dog_food'))
            ->toBeFalse();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/groceries/en-GB/products/123456'))
            ->toBeFalse();
    });
});

describe('product details extraction', function () {
    test('extracts product details from JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Royal Canin Maxi Adult 15kg",
                "description": "Complete food for large breed adult dogs",
                "brand": {"name": "Royal Canin"},
                "offers": {
                    "@type": "Offer",
                    "price": "54.99",
                    "availability": "https://schema.org/InStock"
                },
                "image": "https://www.zooplus.co.uk/image.jpg"
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/royal_canin/royal-canin-maxi-adult_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0])->toBeInstanceOf(ProductDetails::class)
            ->and($results[0]->title)->toBe('Royal Canin Maxi Adult 15kg')
            ->and($results[0]->description)->toBe('Complete food for large breed adult dogs')
            ->and($results[0]->brand)->toBe('Royal Canin')
            ->and($results[0]->pricePence)->toBe(5499)
            ->and($results[0]->inStock)->toBeTrue();
    });

    test('extracts product details from DOM selectors', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1 data-zta="productTitle">Wolf of Wilderness Adult 12kg</h1>
            <div data-zta="productPriceAmount">£45.99</div>
            <div data-zta="productDescription">Grain-free dry food for adult dogs</div>
        </body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/wolf_of_wilderness/wolf-of-wilderness-adult_456789';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Wolf of Wilderness Adult 12kg')
            ->and($results[0]->pricePence)->toBe(4599)
            ->and($results[0]->description)->toBe('Grain-free dry food for adult dogs');
    });

    test('extracts external ID from URL', function () {
        $html = '<html><body><h1>Product</h1></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product-name_987654';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('987654');
    });

    test('yields ProductDetails DTO with correct retailer metadata', function () {
        $html = '<html><body><h1>Test Product</h1><div class="price">£10.00</div></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['retailer'])->toBe('zooplus-uk')
            ->and($results[0]->metadata['source_url'])->toBe($url);
    });
});

describe('price parsing', function () {
    test('parses price in pounds format', function () {
        expect($this->extractor->parsePriceToPence('£12.99'))->toBe(1299)
            ->and($this->extractor->parsePriceToPence('£54.99'))->toBe(5499)
            ->and($this->extractor->parsePriceToPence('£100.00'))->toBe(10000);
    });

    test('parses price in pence format', function () {
        expect($this->extractor->parsePriceToPence('99p'))->toBe(99)
            ->and($this->extractor->parsePriceToPence('50p'))->toBe(50);
    });

    test('parses price without currency symbol', function () {
        expect($this->extractor->parsePriceToPence('12.99'))->toBe(1299)
            ->and($this->extractor->parsePriceToPence('54.99'))->toBe(5499);
    });

    test('handles empty or invalid price', function () {
        expect($this->extractor->parsePriceToPence(''))->toBeNull()
            ->and($this->extractor->parsePriceToPence('invalid'))->toBeNull();
    });
});

describe('weight parsing', function () {
    test('parses weight in kilograms', function () {
        expect($this->extractor->parseWeight('15kg'))->toBe(15000)
            ->and($this->extractor->parseWeight('2.5kg'))->toBe(2500);
    });

    test('parses weight in grams', function () {
        expect($this->extractor->parseWeight('500g'))->toBe(500)
            ->and($this->extractor->parseWeight('800g'))->toBe(800);
    });

    test('parses weight from product title', function () {
        expect($this->extractor->parseWeight('Royal Canin Maxi Adult 15kg'))->toBe(15000)
            ->and($this->extractor->parseWeight('Wolf of Wilderness 12kg'))->toBe(12000);
    });

    test('parses volume in litres', function () {
        expect($this->extractor->parseWeight('1l'))->toBe(1000)
            ->and($this->extractor->parseWeight('500ml'))->toBe(500);
    });

    test('returns null for text without weight', function () {
        expect($this->extractor->parseWeight('Dog Food Premium'))->toBeNull();
    });
});

describe('brand extraction', function () {
    test('extracts brand from JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "brand": {"name": "Royal Canin"},
                "offers": {"price": "10.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/royal_canin/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Royal Canin');
    });

    test('extracts known brands from title', function () {
        $html = '<html><body><h1>Wolf of Wilderness Adult Dry Dog Food 12kg</h1></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/wolf_of_wilderness/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Wolf of Wilderness');
    });

    test('extracts zooplus own brand from title', function () {
        $html = '<html><body><h1>Concept for Life Large Adult 12kg</h1></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/concept_for_life/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Concept for Life');
    });
});

describe('stock status extraction', function () {
    test('extracts in stock from JSON-LD availability', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "offers": {
                    "price": "10.00",
                    "availability": "https://schema.org/InStock"
                }
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeTrue();
    });

    test('extracts out of stock from JSON-LD availability', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "offers": {
                    "price": "10.00",
                    "availability": "https://schema.org/OutOfStock"
                }
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeFalse();
    });

    test('defaults to in stock when no availability info', function () {
        $html = '<html><body><h1>Test Product</h1></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeTrue();
    });
});

describe('image extraction', function () {
    test('extracts images from JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "image": ["https://www.zooplus.co.uk/image1.jpg", "https://www.zooplus.co.uk/image2.jpg"],
                "offers": {"price": "10.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toHaveCount(2)
            ->and($results[0]->images[0])->toBe('https://www.zooplus.co.uk/image1.jpg');
    });

    test('extracts single image from JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "image": "https://www.zooplus.co.uk/image.jpg",
                "offers": {"price": "10.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toHaveCount(1)
            ->and($results[0]->images[0])->toBe('https://www.zooplus.co.uk/image.jpg');
    });
});

describe('nutritional info extraction', function () {
    test('extracts nutritional info when available', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1>Test Product</h1>
            <div class="analytical-constituents">
                <table>
                    <tr><td>Crude Protein</td><td>25%</td></tr>
                    <tr><td>Crude Fat</td><td>14%</td></tr>
                    <tr><td>Crude Fibre</td><td>3.5%</td></tr>
                </table>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->nutritionalInfo)->not->toBeNull()
            ->and($results[0]->nutritionalInfo)->toHaveKey('Crude Protein')
            ->and($results[0]->nutritionalInfo['Crude Protein'])->toBe('25%');
    });
});

describe('edge cases', function () {
    test('handles empty HTML gracefully', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Unknown Product')
            ->and($results[0]->pricePence)->toBe(0);
    });

    test('handles malformed JSON-LD gracefully', function () {
        $html = '<html><head><script type="application/ld+json">{ invalid json }</script></head><body><h1>Fallback Title</h1></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Fallback Title');
    });

    test('handles missing price gracefully', function () {
        $html = '<html><body><h1>Product Without Price</h1></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->pricePence)->toBe(0);
    });

    test('handles product URL with query parameters', function () {
        $html = '<html><body><h1>Product</h1></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456?variant=15kg';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('123456');
    });
});
