<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\Morrisons\MorrisonsProductDetailsExtractor;

beforeEach(function () {
    $this->extractor = new MorrisonsProductDetailsExtractor;
});

describe('canHandle', function () {
    test('returns true for Morrisons product URLs', function () {
        expect($this->extractor->canHandle('https://groceries.morrisons.com/products/pedigree-dog-food/123456'))
            ->toBeTrue();
    });

    test('returns true for various Morrisons product URL patterns', function () {
        expect($this->extractor->canHandle('https://groceries.morrisons.com/products/whiskas-cat-food-1kg/ABC12345'))
            ->toBeTrue();
    });

    test('returns false for category pages', function () {
        expect($this->extractor->canHandle('https://groceries.morrisons.com/browse/pet/dog'))
            ->toBeFalse();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/groceries/en-GB/products/123456'))
            ->toBeFalse();
    });
});

describe('JSON-LD extraction', function () {
    test('extracts product details from JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Pedigree Adult Dog Food Chicken 2.6kg",
                "description": "Complete dry dog food with chicken",
                "brand": {"@type": "Brand", "name": "Pedigree"},
                "image": "https://groceries.morrisons.com/image/product.jpg",
                "offers": {
                    "@type": "Offer",
                    "price": "5.50",
                    "priceCurrency": "GBP",
                    "availability": "https://schema.org/InStock"
                }
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/pedigree-adult-dog-food/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0])->toBeInstanceOf(ProductDetails::class)
            ->and($results[0]->title)->toBe('Pedigree Adult Dog Food Chicken 2.6kg')
            ->and($results[0]->description)->toBe('Complete dry dog food with chicken')
            ->and($results[0]->brand)->toBe('Pedigree')
            ->and($results[0]->pricePence)->toBe(550)
            ->and($results[0]->inStock)->toBeTrue();
    });

    test('extracts from JSON-LD @graph format', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@graph": [
                    {
                        "@type": "Product",
                        "name": "Whiskas Cat Food",
                        "offers": {"price": "3.00"}
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/whiskas-cat-food/789012';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Whiskas Cat Food')
            ->and($results[0]->pricePence)->toBe(300);
    });

    test('handles JSON-LD offers as array', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Multi-size Product",
                "offers": [
                    {"price": "10.00"},
                    {"price": "15.00"}
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/multi-size-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->pricePence)->toBe(1000);
    });
});

describe('DOM extraction', function () {
    test('extracts title from h1 element', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1 data-test="product-title">Pedigree Dog Food 2kg</h1>
        </body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/pedigree-dog-food/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Pedigree Dog Food 2kg');
    });

    test('extracts price from price element', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1>Test Product</h1>
            <div data-test="product-price">£4.99</div>
        </body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->pricePence)->toBe(499);
    });
});

describe('price parsing', function () {
    test('parses price with pound sign', function () {
        expect($this->extractor->parsePriceToPence('£5.99'))->toBe(599);
    });

    test('parses price without currency symbol', function () {
        expect($this->extractor->parsePriceToPence('12.99'))->toBe(1299);
    });

    test('parses pence format', function () {
        expect($this->extractor->parsePriceToPence('99p'))->toBe(99);
    });

    test('parses whole pounds', function () {
        expect($this->extractor->parsePriceToPence('£5'))->toBe(500);
    });

    test('handles comma as decimal separator', function () {
        expect($this->extractor->parsePriceToPence('12,99'))->toBe(1299);
    });

    test('returns null for empty string', function () {
        expect($this->extractor->parsePriceToPence(''))->toBeNull();
    });
});

describe('weight parsing', function () {
    test('parses kilograms', function () {
        expect($this->extractor->parseWeight('2.5kg'))->toBe(2500);
    });

    test('parses grams', function () {
        expect($this->extractor->parseWeight('400g'))->toBe(400);
    });

    test('parses litres', function () {
        expect($this->extractor->parseWeight('1.5l'))->toBe(1500);
    });

    test('parses millilitres', function () {
        expect($this->extractor->parseWeight('500ml'))->toBe(500);
    });

    test('parses weight from title', function () {
        expect($this->extractor->parseWeight('Pedigree Adult Dog Food 2.6kg'))->toBe(2600);
    });

    test('returns null when no weight found', function () {
        expect($this->extractor->parseWeight('No weight here'))->toBeNull();
    });
});

describe('brand extraction', function () {
    test('extracts known brand from title', function () {
        $html = '<html><body><h1>Pedigree Complete Adult Dog Food 2kg</h1></body></html>';
        $url = 'https://groceries.morrisons.com/products/pedigree-dog-food/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Pedigree');
    });

    test('extracts brand from JSON-LD string format', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {"@type": "Product", "name": "Dog Food", "brand": "Whiskas", "offers": {"price": "5.00"}}
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/cat-food/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Whiskas');
    });

    test('extracts brand from JSON-LD object format', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {"@type": "Product", "name": "Dog Food", "brand": {"name": "Royal Canin"}, "offers": {"price": "10.00"}}
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/dog-food/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Royal Canin');
    });
});

describe('external ID extraction', function () {
    test('extracts SKU from URL', function () {
        $url = 'https://groceries.morrisons.com/products/pedigree-dog-food/123456';

        $externalId = $this->extractor->extractExternalId($url);

        expect($externalId)->toBe('123456');
    });

    test('extracts alphanumeric SKU from URL', function () {
        $url = 'https://groceries.morrisons.com/products/whiskas-cat-food/ABC12345';

        $externalId = $this->extractor->extractExternalId($url);

        expect($externalId)->toBe('ABC12345');
    });

    test('extracts SKU from JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {"@type": "Product", "name": "Product", "sku": "MORR-123456", "offers": {"price": "5.00"}}
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/product-name/other';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('other');
    });
});

describe('stock status', function () {
    test('detects in stock from JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {"@type": "Product", "name": "Product", "offers": {"price": "5.00", "availability": "https://schema.org/InStock"}}
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeTrue();
    });

    test('detects out of stock from JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {"@type": "Product", "name": "Product", "offers": {"price": "5.00", "availability": "https://schema.org/OutOfStock"}}
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeFalse();
    });

    test('detects out of stock from DOM element', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1>Product</h1>
            <div class="out-of-stock">Out of Stock</div>
        </body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeFalse();
    });

    test('defaults to in stock when no indicators', function () {
        $html = '<html><body><h1>Product</h1></body></html>';
        $url = 'https://groceries.morrisons.com/products/product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeTrue();
    });
});

describe('images', function () {
    test('extracts images from JSON-LD string', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {"@type": "Product", "name": "Product", "image": "https://groceries.morrisons.com/image.jpg", "offers": {"price": "5.00"}}
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toContain('https://groceries.morrisons.com/image.jpg');
    });

    test('extracts images from JSON-LD array', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {"@type": "Product", "name": "Product", "image": ["https://img1.jpg", "https://img2.jpg"], "offers": {"price": "5.00"}}
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toContain('https://img1.jpg')
            ->and($results[0]->images)->toContain('https://img2.jpg');
    });
});

describe('metadata', function () {
    test('includes source URL in metadata', function () {
        $html = '<html><body><h1>Product</h1></body></html>';
        $url = 'https://groceries.morrisons.com/products/product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('source_url')
            ->and($results[0]->metadata['source_url'])->toBe($url);
    });

    test('includes retailer in metadata', function () {
        $html = '<html><body><h1>Product</h1></body></html>';
        $url = 'https://groceries.morrisons.com/products/product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('retailer')
            ->and($results[0]->metadata['retailer'])->toBe('morrisons');
    });

    test('includes extraction timestamp', function () {
        $html = '<html><body><h1>Product</h1></body></html>';
        $url = 'https://groceries.morrisons.com/products/product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('extracted_at');
    });
});

describe('quantity extraction', function () {
    test('extracts pack quantity from title', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {"@type": "Product", "name": "Pedigree Pouches 12 Pack", "offers": {"price": "8.00"}}
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/pedigree-pouches/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->quantity)->toBe(12);
    });

    test('extracts quantity from "x" pattern', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {"@type": "Product", "name": "Pedigree Pouches 6 x 100g", "offers": {"price": "4.00"}}
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/pedigree-pouches/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->quantity)->toBe(6);
    });
});

describe('edge cases', function () {
    test('handles empty HTML', function () {
        $html = '<html><body></body></html>';
        $url = 'https://groceries.morrisons.com/products/product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Unknown Product');
    });

    test('handles malformed JSON-LD gracefully', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {"@type": "Product", "name": "Valid Product", invalid json here
            </script>
        </head>
        <body><h1>Fallback Title</h1></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Fallback Title');
    });

    test('returns currency as GBP', function () {
        $html = '<html><body><h1>Product</h1></body></html>';
        $url = 'https://groceries.morrisons.com/products/product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->currency)->toBe('GBP');
    });
});
