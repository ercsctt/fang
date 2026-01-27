<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductReview;
use App\Crawler\Extractors\JustForPets\JFPProductReviewsExtractor;

beforeEach(function () {
    $this->extractor = new JFPProductReviewsExtractor;
});

describe('JFPProductReviewsExtractor canHandle', function () {
    test('handles JFP product URLs for reviews', function () {
        expect($this->extractor->canHandle('https://www.justforpetsonline.co.uk/product/test-product-123'))
            ->toBeTrue();
    });

    test('handles JFP slash p slash pattern URLs for reviews', function () {
        expect($this->extractor->canHandle('https://www.justforpetsonline.co.uk/p/12345'))
            ->toBeTrue();
    });

    test('handles JFP dash p dash pattern URLs for reviews', function () {
        expect($this->extractor->canHandle('https://www.justforpetsonline.co.uk/dog-food/product-p-123.html'))
            ->toBeTrue();
    });

    test('handles JFP slug-id.html pattern URLs for reviews', function () {
        expect($this->extractor->canHandle('https://www.justforpetsonline.co.uk/dog-food/product-123.html'))
            ->toBeTrue();
    });

    test('rejects JFP category pages for reviews', function () {
        expect($this->extractor->canHandle('https://www.justforpetsonline.co.uk/dog/dog-food/'))
            ->toBeFalse();
    });

    test('rejects other domains for reviews', function () {
        expect($this->extractor->canHandle('https://www.petsathome.com/product/test-123'))
            ->toBeFalse();
    });
});

describe('JSON-LD extraction', function () {
    test('extracts reviews from JSON-LD structured data', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "review": [
                    {
                        "@type": "Review",
                        "reviewRating": {
                            "ratingValue": "5"
                        },
                        "author": "John Smith",
                        "reviewBody": "Excellent dog food, my dog loves it!",
                        "datePublished": "2024-01-15"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/test-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0])->toBeInstanceOf(ProductReview::class)
            ->and($results[0]->rating)->toBe(5.0)
            ->and($results[0]->author)->toBe('John Smith')
            ->and($results[0]->body)->toBe('Excellent dog food, my dog loves it!');
    });

    test('extracts multiple reviews from JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "review": [
                    {
                        "reviewRating": {"ratingValue": "5"},
                        "author": "User 1",
                        "reviewBody": "Great product!"
                    },
                    {
                        "reviewRating": {"ratingValue": "4"},
                        "author": "User 2",
                        "reviewBody": "Good quality"
                    },
                    {
                        "reviewRating": {"ratingValue": "3"},
                        "author": "User 3",
                        "reviewBody": "Average"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/multi-reviews-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(3);
    });

    test('extracts reviews from JSON-LD @graph format', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@graph": [
                    {
                        "@type": "Product",
                        "name": "Test Product",
                        "review": [
                            {
                                "reviewRating": {"ratingValue": "4"},
                                "author": "Graph User",
                                "reviewBody": "Found in graph"
                            }
                        ]
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/graph-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->body)->toBe('Found in graph');
    });

    test('handles author as object in JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "reviewRating": {"ratingValue": "5"},
                        "author": {"@type": "Person", "name": "Object Author"},
                        "reviewBody": "Test review"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/author-object-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->author)->toBe('Object Author');
    });

    test('extracts review title from name or headline', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "reviewRating": {"ratingValue": "5"},
                        "author": "Test User",
                        "name": "Best dog food ever!",
                        "reviewBody": "My dog loves this food."
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/with-title-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Best dog food ever!');
    });

    test('skips reviews without rating', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "author": "No Rating User",
                        "reviewBody": "Review without rating"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/no-rating-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('skips reviews without body', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "reviewRating": {"ratingValue": "5"},
                        "author": "No Body User"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/no-body-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });
});

describe('DOM extraction fallback', function () {
    test('extracts reviews from DOM when JSON-LD unavailable', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="5">
                <span class="review-author">DOM User</span>
                <p class="review-body">Great product from DOM!</p>
                <time datetime="2024-01-20">20 January 2024</time>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/dom-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->rating)->toBe(5.0)
            ->and($results[0]->author)->toBe('DOM User')
            ->and($results[0]->body)->toBe('Great product from DOM!');
    });

    test('extracts multiple reviews from DOM', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="5">
                <span class="review-author">User 1</span>
                <p class="review-body">Review 1</p>
            </div>
            <div class="review-item" data-rating="4">
                <span class="review-author">User 2</span>
                <p class="review-body">Review 2</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/multi-dom-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(2);
    });

    test('extracts rating from nested element with data attribute', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item">
                <div class="review-rating" data-rating="4"></div>
                <p class="review-body">Review with nested rating</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/nested-rating-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->rating)->toBe(4.0);
    });

    test('extracts rating from itemprop', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item">
                <meta itemprop="ratingValue" content="4.5">
                <p class="review-body">Review with itemprop rating</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/itemprop-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->rating)->toBe(4.5);
    });

    test('extracts review title from DOM', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="5">
                <h3 class="review-title">Amazing Product</h3>
                <p class="review-body">Really love this!</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/dom-title-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Amazing Product');
    });

    test('extracts rating by counting filled stars', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item">
                <span class="star-filled"></span>
                <span class="star-filled"></span>
                <span class="star-filled"></span>
                <span class="star-filled"></span>
                <span class="star-empty"></span>
                <p class="review-body">Four star review</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/star-count-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->rating)->toBe(4.0);
    });

    test('extracts rating from WooCommerce style percentage', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item">
                <span class="star-rating" style="width: 80%"></span>
                <p class="review-body">WooCommerce style review</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/woo-rating-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->rating)->toBe(4.0);
    });
});

describe('review date extraction', function () {
    test('extracts date from JSON-LD datePublished', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "reviewRating": {"ratingValue": "5"},
                        "author": "Test User",
                        "reviewBody": "Test review",
                        "datePublished": "2024-06-15"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/dated-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->reviewDate)->not->toBeNull()
            ->and($results[0]->reviewDate->format('Y-m-d'))->toBe('2024-06-15');
    });

    test('extracts date from DOM time element', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="4">
                <p class="review-body">Test review</p>
                <time datetime="2024-03-20">20 March 2024</time>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/dom-date-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->reviewDate)->not->toBeNull()
            ->and($results[0]->reviewDate->format('Y-m-d'))->toBe('2024-03-20');
    });
});

describe('verified purchase', function () {
    test('extracts verified purchase from JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "reviewRating": {"ratingValue": "5"},
                        "author": "Verified User",
                        "reviewBody": "Verified review",
                        "verifiedPurchase": true
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/verified-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->verifiedPurchase)->toBeTrue();
    });

    test('extracts verified purchase from DOM element', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="5">
                <p class="review-body">Verified DOM review</p>
                <span class="verified-purchase">Verified Purchase</span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/dom-verified-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->verifiedPurchase)->toBeTrue();
    });

    test('defaults to false when not verified', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "reviewRating": {"ratingValue": "4"},
                        "author": "Regular User",
                        "reviewBody": "Regular review"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/regular-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->verifiedPurchase)->toBeFalse();
    });
});

describe('external ID generation', function () {
    test('uses @id from JSON-LD when available', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "@id": "review-12345",
                        "reviewRating": {"ratingValue": "5"},
                        "author": "Test User",
                        "reviewBody": "Test review"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/with-id-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('review-12345');
    });

    test('uses data-review-id from DOM when available', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="5" data-review-id="dom-review-999">
                <p class="review-body">DOM review</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/dom-id-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('dom-review-999');
    });

    test('generates deterministic ID when not provided', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "reviewRating": {"ratingValue": "5"},
                        "author": "Test User",
                        "reviewBody": "Test review body"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/gen-id-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toStartWith('jfp-review-')
            ->and($results[0]->externalId)->toEndWith('-0');
    });

    test('generates consistent IDs for same content', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "reviewRating": {"ratingValue": "5"},
                        "author": "Same User",
                        "reviewBody": "Same review body"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/consistent-123';

        $results1 = iterator_to_array($this->extractor->extract($html, $url));
        $results2 = iterator_to_array($this->extractor->extract($html, $url));

        expect($results1[0]->externalId)->toBe($results2[0]->externalId);
    });
});

describe('metadata', function () {
    test('includes source and URL in metadata', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "reviewRating": {"ratingValue": "5"},
                        "author": "Test",
                        "reviewBody": "Test"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/metadata-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['source'])->toBe('json-ld')
            ->and($results[0]->metadata['source_url'])->toBe($url)
            ->and($results[0]->metadata)->toHaveKey('extracted_at');
    });

    test('includes dom source when extracted from DOM', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="5">
                <p class="review-body">DOM review</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/dom-source-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['source'])->toBe('dom');
    });
});

describe('edge cases', function () {
    test('handles empty HTML', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.justforpetsonline.co.uk/product/empty-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles malformed JSON-LD gracefully', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            { invalid json }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/malformed-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles Product without reviews array', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Product without reviews"
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/no-reviews-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('prefers JSON-LD over DOM when both available', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "reviewRating": {"ratingValue": "5"},
                        "author": "JSON User",
                        "reviewBody": "JSON review"
                    }
                ]
            }
            </script>
        </head>
        <body>
            <div class="review-item" data-rating="3">
                <span class="review-author">DOM User</span>
                <p class="review-body">DOM review</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/both-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->author)->toBe('JSON User')
            ->and($results[0]->metadata['source'])->toBe('json-ld');
    });
});

describe('helpful count', function () {
    test('extracts helpful count from JSON-LD upvoteCount', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "reviewRating": {"ratingValue": "5"},
                        "author": "Test",
                        "reviewBody": "Helpful review",
                        "upvoteCount": 42
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/helpful-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->helpfulCount)->toBe(42);
    });

    test('defaults helpful count to zero', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "reviewRating": {"ratingValue": "5"},
                        "author": "Test",
                        "reviewBody": "Review"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/no-helpful-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->helpfulCount)->toBe(0);
    });
});

describe('different review selectors', function () {
    test('extracts reviews from customer-review selector', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="customer-review" data-rating="5">
                <span class="review-author">Customer User</span>
                <p class="review-body">Customer review content</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/customer-review-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->body)->toBe('Customer review content');
    });

    test('extracts reviews from product-review selector', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="product-review" data-rating="4">
                <span class="review-author">Product Reviewer</span>
                <p class="review-body">Product review content</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/product-review-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->body)->toBe('Product review content');
    });

    test('extracts reviews from WooCommerce Reviews selector', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="woocommerce-Reviews">
                <div class="review" data-rating="5">
                    <span class="woocommerce-review__author">WooCommerce User</span>
                    <p class="description">WooCommerce review content</p>
                </div>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.justforpetsonline.co.uk/product/woo-reviews-123';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->body)->toBe('WooCommerce review content');
    });
});
