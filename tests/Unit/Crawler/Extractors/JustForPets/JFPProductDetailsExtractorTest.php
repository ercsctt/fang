<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\JustForPets\JFPProductDetailsExtractor;
use App\Crawler\Services\CategoryExtractor;
use App\Services\ProductNormalizer;

beforeEach(function () {
    $this->extractor = new JFPProductDetailsExtractor(
        app(CategoryExtractor::class)
    );
});

describe('JFPProductDetailsExtractor canHandle', function () {
    test('handles JFP product URLs', function () {
        expect($this->extractor->canHandle('https://www.justforpetsonline.co.uk/product/pedigree-adult-12kg'))
            ->toBeTrue();
    });

    test('handles JFP product URLs with numeric ID', function () {
        expect($this->extractor->canHandle('https://www.justforpetsonline.co.uk/products/royal-canin-123'))
            ->toBeTrue();
    });

    test('handles JFP slash p slash pattern URLs for details', function () {
        expect($this->extractor->canHandle('https://www.justforpetsonline.co.uk/p/12345'))
            ->toBeTrue();
    });

    test('handles JFP dash p dash pattern URLs for details', function () {
        expect($this->extractor->canHandle('https://www.justforpetsonline.co.uk/dog-food/product-p-123.html'))
            ->toBeTrue();
    });

    test('handles JFP slug-id.html pattern URLs for details', function () {
        expect($this->extractor->canHandle('https://www.justforpetsonline.co.uk/dog-food/product-123.html'))
            ->toBeTrue();
    });

    test('rejects JFP category pages for details', function () {
        expect($this->extractor->canHandle('https://www.justforpetsonline.co.uk/dog/dog-food/'))
            ->toBeFalse();
    });

    test('rejects other domains for details', function () {
        expect($this->extractor->canHandle('https://www.petsathome.com/product/test-123'))
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
                "name": "Pedigree Complete Adult Dry Dog Food with Chicken 2.6kg",
                "description": "Complete pet food for adult dogs",
                "brand": {
                    "@type": "Brand",
                    "name": "Pedigree"
                },
                "offers": {
                    "@type": "Offer",
                    "price": "5.50",
                    "priceCurrency": "GBP",
                    "availability": "https://schema.org/InStock"
                },
                "image": ["https://www.justforpetsonline.co.uk/media/product/image.jpg"],
                "sku": "JFP123"
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/pedigree-food-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0])->toBeInstanceOf(ProductDetails::class)
            ->and($results[0]->title)->toBe('Pedigree Complete Adult Dry Dog Food with Chicken 2.6kg')
            ->and($results[0]->brand)->toBe('Pedigree')
            ->and($results[0]->pricePence)->toBe(550)
            ->and($results[0]->inStock)->toBeTrue();
    });

    test('extracts product from JSON-LD @graph format', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@graph": [
                    {
                        "@type": "Product",
                        "name": "Royal Canin Dog Food 3kg",
                        "offers": {
                            "@type": "Offer",
                            "price": "25.00"
                        }
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/royal-canin-456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Royal Canin Dog Food 3kg')
            ->and($results[0]->pricePence)->toBe(2500);
    });

    test('handles brand as string in JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Harringtons Dog Food 2kg",
                "brand": "Harringtons",
                "offers": {"price": "8.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/harringtons-789';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Harringtons');
    });

    test('handles offers as array in JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Multi-size Product",
                "offers": [
                    {"price": "10.00", "name": "Small"},
                    {"price": "20.00", "name": "Large"}
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/multi-size-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->pricePence)->toBe(1000);
    });
});

describe('DOM extraction fallback', function () {
    test('extracts title from DOM when JSON-LD unavailable', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1 class="product-title">Bakers Adult Dog Food Beef 5kg</h1>
            <span class="product-price">£10.00</span>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/bakers-456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Bakers Adult Dog Food Beef 5kg');
    });

    test('extracts title from h1 fallback', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1>Generic Product Title</h1>
            <span class="price">£5.00</span>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/generic-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Generic Product Title');
    });

    test('extracts price from itemprop selector', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1>Test Product</h1>
            <span itemprop="price" content="7.50">£7.50</span>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/test-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->pricePence)->toBe(750);
    });
});

describe('price parsing', function () {
    test('parses price in pounds format', function () {
        expect($this->extractor->parsePriceToPence('£12.99'))->toBe(1299);
    });

    test('parses price in pence format', function () {
        expect($this->extractor->parsePriceToPence('99p'))->toBe(99);
    });

    test('parses price without currency symbol', function () {
        expect($this->extractor->parsePriceToPence('5.50'))->toBe(550);
    });

    test('handles comma as decimal separator', function () {
        expect($this->extractor->parsePriceToPence('10,99'))->toBe(1099);
    });

    test('returns null for invalid price', function () {
        expect($this->extractor->parsePriceToPence('invalid'))->toBeNull();
    });

    test('returns null for empty string', function () {
        expect($this->extractor->parsePriceToPence(''))->toBeNull();
    });
});

describe('weight parsing', function () {
    test('parses weight in kilograms', function () {
        expect(app(ProductNormalizer::class)->parseWeight('2.5kg'))->toBe(2500);
    });

    test('parses weight in grams', function () {
        expect(app(ProductNormalizer::class)->parseWeight('400g'))->toBe(400);
    });

    test('parses weight in litres', function () {
        expect(app(ProductNormalizer::class)->parseWeight('1.5l'))->toBe(1500);
    });

    test('parses weight with space before unit', function () {
        expect(app(ProductNormalizer::class)->parseWeight('3 kg'))->toBe(3000);
    });

    test('parses weight in pounds', function () {
        expect(app(ProductNormalizer::class)->parseWeight('5lb'))->toBe(2270); // 5 * 454.0
    });

    test('parses weight in ounces', function () {
        expect(app(ProductNormalizer::class)->parseWeight('10oz'))->toBe(280);
    });

    test('returns null for no weight found', function () {
        expect(app(ProductNormalizer::class)->parseWeight('Dog Food Adult'))->toBeNull();
    });

    test('extracts weight from product title', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Pedigree Adult Dry Dog Food 12kg",
                "offers": {"price": "20.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/pedigree-12kg';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->weightGrams)->toBe(12000);
    });
});

describe('quantity extraction', function () {
    test('extracts quantity from pack format in title', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Pedigree Wet Dog Food 12 x 400g",
                "offers": {"price": "10.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/pedigree-wet';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->quantity)->toBe(12);
    });

    test('extracts quantity from pack word', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Dog Treats 6 pack",
                "offers": {"price": "3.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/treats-6pack';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->quantity)->toBe(6);
    });
});

describe('external ID extraction', function () {
    test('extracts SKU from JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "sku": "JFP-SKU-123",
                "offers": {"price": "5.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/test-product';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('JFP-SKU-123');
    });

    test('extracts ID from URL when JSON-LD has no SKU', function () {
        expect($this->extractor->extractExternalId('https://www.justforpetsonline.co.uk/product/test-product-123'))
            ->toBe('123');
    });

    test('extracts ID from slash p slash pattern URL', function () {
        expect($this->extractor->extractExternalId('https://www.justforpetsonline.co.uk/p/45678'))
            ->toBe('45678');
    });

    test('extracts ID from dash p dash pattern URL', function () {
        expect($this->extractor->extractExternalId('https://www.justforpetsonline.co.uk/dog-food/product-p-999.html'))
            ->toBe('999');
    });

    test('extracts slug as ID for .html URLs without numeric ID', function () {
        expect($this->extractor->extractExternalId('https://www.justforpetsonline.co.uk/dog-food/premium-product.html'))
            ->toBe('premium-product');
    });
});

describe('stock status', function () {
    test('returns true when JSON-LD shows InStock', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "In Stock Product",
                "offers": {
                    "price": "5.00",
                    "availability": "https://schema.org/InStock"
                }
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/instock-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeTrue();
    });

    test('returns false when JSON-LD shows OutOfStock', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Out of Stock Product",
                "offers": {
                    "price": "5.00",
                    "availability": "https://schema.org/OutOfStock"
                }
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/outofstock-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeFalse();
    });

    test('returns false when out-of-stock element present', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1>Test Product</h1>
            <span class="price">£5.00</span>
            <div class="out-of-stock">Currently unavailable</div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/unavailable-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeFalse();
    });

    test('defaults to in stock when no indicators present', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1>Test Product</h1>
            <span class="price">£5.00</span>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/default-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeTrue();
    });
});

describe('brand extraction from title', function () {
    test('extracts known brand from title', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Pedigree Adult Complete Dog Food 12kg",
                "offers": {"price": "20.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/pedigree-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Pedigree');
    });

    test('extracts Lilys Kitchen brand', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Lily's Kitchen Adult Dog Food Chicken 2.5kg",
                "offers": {"price": "15.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/lilys-kitchen-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe("Lily's Kitchen");
    });

    test('extracts James Wellbeloved brand', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "James Wellbeloved Adult Turkey Dog Food 2kg",
                "offers": {"price": "16.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/james-wellbeloved-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('James Wellbeloved');
    });
});

describe('metadata', function () {
    test('includes retailer in metadata', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {"@type": "Product", "name": "Test", "offers": {"price": "5.00"}}
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/test-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['retailer'])->toBe('just-for-pets')
            ->and($results[0]->metadata['source_url'])->toBe($url)
            ->and($results[0]->metadata)->toHaveKey('extracted_at');
    });

    test('includes rating data when available', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Rated Product",
                "offers": {"price": "5.00"},
                "aggregateRating": {
                    "ratingValue": "4.5",
                    "reviewCount": "123"
                }
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/rated-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['rating_value'])->toBe('4.5')
            ->and($results[0]->metadata['review_count'])->toBe('123');
    });
});

describe('images extraction', function () {
    test('extracts images from JSON-LD array', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Product with Images",
                "offers": {"price": "5.00"},
                "image": [
                    "https://www.justforpetsonline.co.uk/media/image1.jpg",
                    "https://www.justforpetsonline.co.uk/media/image2.jpg"
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/images-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toHaveCount(2)
            ->and($results[0]->images[0])->toContain('image1.jpg');
    });

    test('extracts single image from JSON-LD string', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Product with Single Image",
                "offers": {"price": "5.00"},
                "image": "https://www.justforpetsonline.co.uk/media/single.jpg"
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/single-image-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toHaveCount(1)
            ->and($results[0]->images[0])->toContain('single.jpg');
    });

    test('extracts images from DOM when JSON-LD unavailable', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1>Test Product</h1>
            <span class="price">£5.00</span>
            <div class="product-image">
                <img src="https://www.justforpetsonline.co.uk/media/dom-image.jpg">
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/dom-images-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toHaveCount(1)
            ->and($results[0]->images[0])->toContain('dom-image.jpg');
    });
});

describe('edge cases', function () {
    test('handles missing title gracefully', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.justforpetsonline.co.uk/product/no-title-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Unknown Product');
    });

    test('handles missing price gracefully', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {"@type": "Product", "name": "No Price Product"}
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/no-price-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->pricePence)->toBe(0);
    });

    test('handles malformed JSON-LD gracefully', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            { invalid json }
            </script>
        </head>
        <body>
            <h1>Test Product</h1>
            <span class="price">£5.00</span>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/malformed-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Test Product');
    });

    test('handles empty HTML', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.justforpetsonline.co.uk/product/empty-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });
});

describe('description extraction', function () {
    test('extracts description from JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "description": "Premium quality dog food for adult dogs",
                "offers": {"price": "10.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/desc-test-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->description)->toBe('Premium quality dog food for adult dogs');
    });

    test('extracts description from DOM when JSON-LD unavailable', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1>Test Product</h1>
            <span class="price">£5.00</span>
            <div class="product-description">Nutritious food for your pet</div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/dom-desc-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->description)->toBe('Nutritious food for your pet');
    });
});

describe('original price extraction', function () {
    test('extracts original price from was-price element', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1>Test Product</h1>
            <span class="price">£8.00</span>
            <span class="was-price">£10.00</span>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/sale-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->originalPricePence)->toBe(1000);
    });
});
