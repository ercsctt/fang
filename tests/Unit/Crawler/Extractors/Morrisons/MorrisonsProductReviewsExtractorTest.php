<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductReview;
use App\Crawler\Extractors\Morrisons\MorrisonsProductReviewsExtractor;

beforeEach(function () {
    $this->extractor = new MorrisonsProductReviewsExtractor;
});

describe('canHandle', function () {
    test('returns true for Morrisons product URLs', function () {
        expect($this->extractor->canHandle('https://groceries.morrisons.com/products/pedigree-dog-food/123456'))
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

describe('JSON-LD review extraction', function () {
    test('extracts reviews from JSON-LD', function () {
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
                        "author": "John Doe",
                        "reviewBody": "Great product, my dog loves it!",
                        "reviewRating": {"ratingValue": 5},
                        "datePublished": "2024-01-15"
                    },
                    {
                        "@type": "Review",
                        "author": "Jane Smith",
                        "reviewBody": "Good quality but a bit expensive",
                        "reviewRating": {"ratingValue": 4},
                        "datePublished": "2024-01-10"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(2)
            ->and($results[0])->toBeInstanceOf(ProductReview::class)
            ->and($results[0]->author)->toBe('John Doe')
            ->and($results[0]->body)->toBe('Great product, my dog loves it!')
            ->and($results[0]->rating)->toBe(5.0)
            ->and($results[1]->author)->toBe('Jane Smith')
            ->and($results[1]->rating)->toBe(4.0);
    });

    test('extracts review from JSON-LD @graph format', function () {
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
                                "author": "Test User",
                                "reviewBody": "Nice product",
                                "reviewRating": {"ratingValue": 4}
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
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->author)->toBe('Test User');
    });

    test('handles author as object with name property', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "review": [
                    {
                        "author": {"@type": "Person", "name": "Object Author"},
                        "reviewBody": "Review text",
                        "reviewRating": {"ratingValue": 5}
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

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
                "name": "Test Product",
                "review": [
                    {
                        "name": "Great Product!",
                        "author": "Test User",
                        "reviewBody": "Really good",
                        "reviewRating": {"ratingValue": 5}
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Great Product!');
    });

    test('extracts verified purchase status', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "review": [
                    {
                        "author": "Verified Buyer",
                        "reviewBody": "Verified review",
                        "reviewRating": {"ratingValue": 5},
                        "verifiedPurchase": true
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->verifiedPurchase)->toBeTrue();
    });

    test('parses review date', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "review": [
                    {
                        "author": "Test User",
                        "reviewBody": "Test review",
                        "reviewRating": {"ratingValue": 4},
                        "datePublished": "2024-06-15"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->reviewDate)->not->toBeNull()
            ->and($results[0]->reviewDate->format('Y-m-d'))->toBe('2024-06-15');
    });

    test('skips reviews without rating', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
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
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

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
                "name": "Test Product",
                "review": [
                    {
                        "author": "No Body User",
                        "reviewRating": {"ratingValue": 5}
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });
});

describe('DOM review extraction', function () {
    test('extracts reviews from DOM when no JSON-LD', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="5">
                <div class="review-author">DOM Author</div>
                <div class="review-body">DOM review body text</div>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->author)->toBe('DOM Author')
            ->and($results[0]->body)->toBe('DOM review body text')
            ->and($results[0]->rating)->toBe(5.0);
    });

    test('extracts rating from data attributes', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-score="4">
                <div class="review-body">Review text</div>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->rating)->toBe(4.0);
    });

    test('extracts rating from nested element data attribute', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item">
                <div class="rating" data-rating="3"></div>
                <div class="review-body">Review text</div>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->rating)->toBe(3.0);
    });

    test('extracts review title', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="5">
                <h3 class="review-title">Great Product</h3>
                <div class="review-body">Review text</div>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Great Product');
    });

    test('extracts review date from time element', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="4">
                <div class="review-body">Review text</div>
                <time datetime="2024-03-20">20 March 2024</time>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->reviewDate)->not->toBeNull()
            ->and($results[0]->reviewDate->format('Y-m-d'))->toBe('2024-03-20');
    });

    test('detects verified purchase from class', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="5">
                <div class="review-body">Review text</div>
                <span class="verified-purchase">Verified Purchase</span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->verifiedPurchase)->toBeTrue();
    });

    test('extracts helpful count', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="4">
                <div class="review-body">Review text</div>
                <span class="helpful-count">5 people found this helpful</span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->helpfulCount)->toBe(5);
    });
});

describe('external ID generation', function () {
    test('uses JSON-LD @id when available', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "review": [
                    {
                        "@id": "review-12345",
                        "author": "Test User",
                        "reviewBody": "Test review",
                        "reviewRating": {"ratingValue": 5}
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('review-12345');
    });

    test('uses DOM data-review-id when available', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="5" data-review-id="dom-review-456">
                <div class="review-body">Review text</div>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('dom-review-456');
    });

    test('generates ID when not provided', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "review": [
                    {
                        "author": "Test User",
                        "reviewBody": "Test review",
                        "reviewRating": {"ratingValue": 5}
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toStartWith('morrisons-review-');
    });
});

describe('metadata', function () {
    test('includes source URL in metadata', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "review": [
                    {
                        "author": "Test User",
                        "reviewBody": "Test review",
                        "reviewRating": {"ratingValue": 5}
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('source_url')
            ->and($results[0]->metadata['source_url'])->toBe($url);
    });

    test('includes source type in metadata', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "review": [
                    {
                        "author": "Test User",
                        "reviewBody": "Test review",
                        "reviewRating": {"ratingValue": 5}
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('source')
            ->and($results[0]->metadata['source'])->toBe('json-ld');
    });
});

describe('edge cases', function () {
    test('handles no reviews in HTML', function () {
        $html = '<html><body><h1>Product</h1></body></html>';
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles malformed JSON-LD gracefully', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {"@type": "Product", "review": invalid json
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles multiple JSON-LD scripts', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {"@type": "Organization", "name": "Morrisons"}
            </script>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Test Product",
                "review": [
                    {
                        "author": "Test User",
                        "reviewBody": "Test review",
                        "reviewRating": {"ratingValue": 5}
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://groceries.morrisons.com/products/test-product/123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->author)->toBe('Test User');
    });
});
