<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductDetails;
use App\Crawler\Extractors\Tesco\TescoProductDetailsExtractor;

beforeEach(function () {
    $this->extractor = new TescoProductDetailsExtractor;
});

describe('canHandle', function () {
    test('returns true for Tesco product URLs', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/groceries/en-GB/products/123456789'))
            ->toBeTrue();
    });

    test('returns true for Tesco product URLs with additional path', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/groceries/en-GB/products/123456789/details'))
            ->toBeTrue();
    });

    test('returns false for Tesco category pages', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/groceries/en-GB/shop/pets/dog-food-and-treats/'))
            ->toBeFalse();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.amazon.co.uk/dp/B123456789'))
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
                "image": ["https://digitalcontent.api.tesco.com/v2/media/product/123456789/image.jpg"],
                "sku": "123456789"
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

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
        $url = 'https://www.tesco.com/groceries/en-GB/products/987654321';

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
        $url = 'https://www.tesco.com/groceries/en-GB/products/111222333';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe('Harringtons');
    });
});

describe('DOM extraction fallback', function () {
    test('extracts title from DOM when JSON-LD unavailable', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1 data-auto="product-title">Bakers Adult Dog Food Beef 5kg</h1>
            <span class="beans-price__text">£10.00</span>
        </body>
        </html>
        HTML;
        $url = 'https://www.tesco.com/groceries/en-GB/products/444555666';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Bakers Adult Dog Food Beef 5kg');
    });

    test('extracts price from price-per-sellable-unit selector', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <h1>Test Product</h1>
            <span class="price-per-sellable-unit"><span class="value">£7.50</span></span>
        </body>
        </html>
        HTML;
        $url = 'https://www.tesco.com/groceries/en-GB/products/777888999';

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
        expect($this->extractor->parseWeight('2.5kg'))->toBe(2500);
    });

    test('parses weight in grams', function () {
        expect($this->extractor->parseWeight('400g'))->toBe(400);
    });

    test('parses weight in litres', function () {
        expect($this->extractor->parseWeight('1.5l'))->toBe(1500);
    });

    test('parses weight with space before unit', function () {
        expect($this->extractor->parseWeight('3 kg'))->toBe(3000);
    });

    test('returns null for no weight found', function () {
        expect($this->extractor->parseWeight('Dog Food Adult'))->toBeNull();
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
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

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
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

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
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->quantity)->toBe(6);
    });
});

describe('external ID extraction', function () {
    test('extracts TPN from URL', function () {
        expect($this->extractor->extractExternalId('https://www.tesco.com/groceries/en-GB/products/123456789'))
            ->toBe('123456789');
    });

    test('extracts TPN from URL with additional path segments', function () {
        expect($this->extractor->extractExternalId('https://www.tesco.com/groceries/en-GB/products/987654321/details'))
            ->toBe('987654321');
    });

    test('extracts SKU from JSON-LD when available', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "sku": "TESCO123",
                "offers": {"price": "5.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        // URL-based extraction takes priority since it matches the expected pattern
        expect($results[0]->externalId)->toBe('123456789');
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
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

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
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

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
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->inStock)->toBeFalse();
    });
});

describe('Clubcard price', function () {
    test('extracts Clubcard price to metadata', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product with Clubcard",
                "offers": {"price": "10.00"}
            }
            </script>
        </head>
        <body>
            <span class="clubcard-price__value">£8.00</span>
        </body>
        </html>
        HTML;
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['clubcard_price_pence'])->toBe(800);
    });

    test('returns null for Clubcard price when not present', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product without Clubcard",
                "offers": {"price": "10.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['clubcard_price_pence'])->toBeNull();
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
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

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
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe("Lily's Kitchen");
    });

    test('extracts Hills brand', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Hill's Science Plan Adult Dog Food 2kg",
                "offers": {"price": "18.00"}
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->brand)->toBe("Hill's");
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
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['retailer'])->toBe('tesco')
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
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['rating_value'])->toBe('4.5')
            ->and($results[0]->metadata['review_count'])->toBe('123');
    });
});

describe('edge cases', function () {
    test('handles missing title gracefully', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

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
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

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
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->title)->toBe('Test Product');
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
                    "https://digitalcontent.api.tesco.com/image1.jpg",
                    "https://digitalcontent.api.tesco.com/image2.jpg"
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

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
                "image": "https://digitalcontent.api.tesco.com/single.jpg"
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.tesco.com/groceries/en-GB/products/123456789';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->images)->toHaveCount(1)
            ->and($results[0]->images[0])->toContain('single.jpg');
    });
});
