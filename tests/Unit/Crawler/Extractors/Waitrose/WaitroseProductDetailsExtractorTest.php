<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\Waitrose\WaitroseProductDetailsExtractor;

beforeEach(function () {
    $this->extractor = new WaitroseProductDetailsExtractor;
});

describe('canHandle', function () {
    test('returns true for waitrose.com product URLs', function () {
        expect($this->extractor->canHandle('https://www.waitrose.com/ecom/products/pedigree-dog-food-500g/123456-abc'))
            ->toBeTrue();
    });

    test('returns true for waitrose.com product URLs with complex slugs', function () {
        expect($this->extractor->canHandle('https://www.waitrose.com/ecom/products/lilys-kitchen-organic-dog-food-400g/987654-xyz-123'))
            ->toBeTrue();
    });

    test('returns false for waitrose category pages', function () {
        expect($this->extractor->canHandle('https://www.waitrose.com/ecom/shop/browse/groceries/pet/dog/dog_food'))
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
                "name": "Pedigree Adult Dog Food Chicken 2.6kg",
                "description": "Complete dry food for adult dogs",
                "brand": {"name": "Pedigree"},
                "offers": {
                    "@type": "Offer",
                    "price": "5.50",
                    "availability": "https://schema.org/InStock"
                },
                "image": "https://www.waitrose.com/image.jpg"
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.waitrose.com/ecom/products/pedigree-adult-dog-food-chicken/123456-abc';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0])->toBeInstanceOf(ProductDetails::class)
            ->and($results[0]->title)->toBe('Pedigree Adult Dog Food Chicken 2.6kg')
            ->and($results[0]->description)->toBe('Complete dry food for adult dogs')
            ->and($results[0]->brand)->toBe('Pedigree')
            ->and($results[0]->pricePence)->toBe(550)
            ->and($results[0]->inStock)->toBeTrue();
    });

    test('extracts product details from DOM selectors', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1 data-test="product-name">Royal Canin Adult Dog Food 12kg</h1>
            <div data-test="product-price">£45.99</div>
            <div data-test="product-description">Premium dog food for adult dogs</div>
        </body>
        </html>
        HTML;
        $url = 'https://www.waitrose.com/ecom/products/royal-canin-adult-dog-food/456789-def';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Royal Canin Adult Dog Food 12kg')
            ->and($results[0]->pricePence)->toBe(4599)
            ->and($results[0]->description)->toBe('Premium dog food for adult dogs');
    });

    test('extracts external ID from URL', function () {
        $html = '<html><body><h1>Product</h1></body></html>';
        $url = 'https://www.waitrose.com/ecom/products/pedigree-dog-food/987654-xyz';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('987654-xyz');
    });

    test('yields ProductDetails DTO with correct retailer metadata', function () {
        $html = '<html><body><h1>Test Product</h1><div class="price">£10.00</div></body></html>';
        $url = 'https://www.waitrose.com/ecom/products/test-product/123456-abc';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['retailer'])->toBe('waitrose')
            ->and($results[0]->metadata['source_url'])->toBe($url);
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
        $url = 'https://www.waitrose.com/ecom/products/test-product/123456-abc';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Royal Canin');
    });

    test('extracts known brands from title', function () {
        $html = '<html><body><h1>Pedigree Adult Complete Dry Dog Food 2.6kg</h1></body></html>';
        $url = 'https://www.waitrose.com/ecom/products/pedigree-dog-food/123456-abc';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Pedigree');
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
        $url = 'https://www.waitrose.com/ecom/products/test-product/123456-abc';

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
        $url = 'https://www.waitrose.com/ecom/products/test-product/123456-abc';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeFalse();
    });

    test('defaults to in stock when no availability info', function () {
        $html = '<html><body><h1>Test Product</h1></body></html>';
        $url = 'https://www.waitrose.com/ecom/products/test-product/123456-abc';

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
                "image": ["https://www.waitrose.com/image1.jpg", "https://www.waitrose.com/image2.jpg"],
                "offers": {"price": "10.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.waitrose.com/ecom/products/test-product/123456-abc';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toHaveCount(2)
            ->and($results[0]->images[0])->toBe('https://www.waitrose.com/image1.jpg');
    });

    test('extracts single image from JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "image": "https://www.waitrose.com/image.jpg",
                "offers": {"price": "10.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.waitrose.com/ecom/products/test-product/123456-abc';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toHaveCount(1)
            ->and($results[0]->images[0])->toBe('https://www.waitrose.com/image.jpg');
    });
});

describe('edge cases', function () {
    test('handles empty HTML gracefully', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.waitrose.com/ecom/products/test-product/123456-abc';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Unknown Product')
            ->and($results[0]->pricePence)->toBe(0);
    });

    test('handles malformed JSON-LD gracefully', function () {
        $html = '<html><head><script type="application/ld+json">{ invalid json }</script></head><body><h1>Fallback Title</h1></body></html>';
        $url = 'https://www.waitrose.com/ecom/products/test-product/123456-abc';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Fallback Title');
    });

    test('handles missing price gracefully', function () {
        $html = '<html><body><h1>Product Without Price</h1></body></html>';
        $url = 'https://www.waitrose.com/ecom/products/test-product/123456-abc';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->pricePence)->toBe(0);
    });
});
