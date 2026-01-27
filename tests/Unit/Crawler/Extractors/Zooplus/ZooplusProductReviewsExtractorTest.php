<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductReview;
use App\Crawler\Extractors\Zooplus\ZooplusProductReviewsExtractor;

beforeEach(function () {
    $this->extractor = new ZooplusProductReviewsExtractor;
});

describe('canHandle', function () {
    test('returns true for zooplus.co.uk product URLs', function () {
        expect($this->extractor->canHandle('https://www.zooplus.co.uk/shop/dogs/dry_dog_food/royal_canin/royal-canin-maxi-adult_123456'))
            ->toBeTrue();
    });

    test('returns true for zooplus.co.uk product URLs with complex slugs', function () {
        expect($this->extractor->canHandle('https://www.zooplus.co.uk/shop/dogs/wet_dog_food/lilys_kitchen/lilys-kitchen-organic-dog-food_987654'))
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

describe('review extraction from JSON-LD', function () {
    test('extracts reviews from JSON-LD structured data', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Royal Canin Maxi Adult",
                "review": [
                    {
                        "@type": "Review",
                        "reviewRating": {"ratingValue": 5},
                        "author": "John Doe",
                        "reviewBody": "Excellent dog food, my dog loves it!",
                        "datePublished": "2024-01-15"
                    },
                    {
                        "@type": "Review",
                        "reviewRating": {"ratingValue": 4},
                        "author": "Jane Smith",
                        "reviewBody": "Good quality food for the price.",
                        "datePublished": "2024-01-10"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/royal_canin/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(2)
            ->and($results[0])->toBeInstanceOf(ProductReview::class)
            ->and($results[0]->rating)->toBe(5.0)
            ->and($results[0]->author)->toBe('John Doe')
            ->and($results[0]->body)->toBe('Excellent dog food, my dog loves it!')
            ->and($results[1]->rating)->toBe(4.0)
            ->and($results[1]->author)->toBe('Jane Smith');
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
                        "name": "Dog Food",
                        "review": [
                            {
                                "@type": "Review",
                                "reviewRating": {"ratingValue": 5},
                                "author": "Test User",
                                "reviewBody": "Great product!"
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
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->rating)->toBe(5.0)
            ->and($results[0]->body)->toBe('Great product!');
    });

    test('skips reviews without valid rating', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Dog Food",
                "review": [
                    {
                        "@type": "Review",
                        "reviewRating": {"ratingValue": 0},
                        "reviewBody": "No rating provided"
                    },
                    {
                        "@type": "Review",
                        "reviewRating": {"ratingValue": 4},
                        "reviewBody": "Valid review with rating"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->rating)->toBe(4.0);
    });

    test('skips reviews without body', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Dog Food",
                "review": [
                    {
                        "@type": "Review",
                        "reviewRating": {"ratingValue": 5}
                    },
                    {
                        "@type": "Review",
                        "reviewRating": {"ratingValue": 4},
                        "reviewBody": "This review has content"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->body)->toBe('This review has content');
    });
});

describe('review extraction from DOM', function () {
    test('extracts reviews from DOM elements', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="5">
                <div class="review-author">Happy Customer</div>
                <div class="review-body">My dog absolutely loves this food!</div>
                <time class="review-date" datetime="2024-02-01">Feb 1, 2024</time>
            </div>
            <div class="review-item" data-rating="4">
                <div class="review-author">Dog Owner</div>
                <div class="review-body">Good value for money.</div>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(2)
            ->and($results[0]->rating)->toBe(5.0)
            ->and($results[0]->author)->toBe('Happy Customer')
            ->and($results[0]->body)->toBe('My dog absolutely loves this food!')
            ->and($results[1]->rating)->toBe(4.0)
            ->and($results[1]->author)->toBe('Dog Owner');
    });

    test('extracts reviews from Zooplus specific selectors', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-zta="reviewItem" data-rating="5">
                <div data-zta="reviewAuthor">Zooplus User</div>
                <div data-zta="reviewBody">Great product from Zooplus!</div>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->rating)->toBe(5.0)
            ->and($results[0]->body)->toBe('Great product from Zooplus!');
    });
});

describe('review metadata', function () {
    test('includes source information in metadata', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "reviewRating": {"ratingValue": 5},
                        "reviewBody": "Test review"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata['source'])->toBe('json-ld')
            ->and($results[0]->metadata['source_url'])->toBe($url)
            ->and($results[0]->metadata)->toHaveKey('extracted_at');
    });

    test('generates unique external ID when not provided', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "review": [
                    {
                        "reviewRating": {"ratingValue": 5},
                        "author": "User1",
                        "reviewBody": "Review content 1"
                    },
                    {
                        "reviewRating": {"ratingValue": 4},
                        "author": "User2",
                        "reviewBody": "Review content 2"
                    }
                ]
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toStartWith('zooplus-review-')
            ->and($results[1]->externalId)->toStartWith('zooplus-review-')
            ->and($results[0]->externalId)->not->toBe($results[1]->externalId);
    });
});

describe('verified purchase detection', function () {
    test('detects verified purchase from class', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="5">
                <div class="verified-purchase">Verified Purchase</div>
                <div class="review-body">Verified buyer review</div>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->verifiedPurchase)->toBeTrue();
    });

    test('returns false when no verified indicator', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item" data-rating="5">
                <div class="review-body">Regular review</div>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->verifiedPurchase)->toBeFalse();
    });
});

describe('edge cases', function () {
    test('handles empty HTML', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles malformed JSON-LD gracefully', function () {
        $html = '<html><head><script type="application/ld+json">{ invalid json }</script></head><body></body></html>';
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles product without reviews', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            {
                "@type": "Product",
                "name": "Dog Food"
            }
            </script>
        </head>
        <body></body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles DOM reviews without rating gracefully', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item">
                <div class="review-body">Review without rating</div>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.zooplus.co.uk/shop/dogs/dry_dog_food/brand/product_123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });
});

describe('buildReviewsUrl', function () {
    test('builds correct review URL', function () {
        $url = ZooplusProductReviewsExtractor::buildReviewsUrl('123456', 1);

        expect($url)->toBe('https://www.zooplus.co.uk/reviews/123456?page=1');
    });

    test('builds URL with page number', function () {
        $url = ZooplusProductReviewsExtractor::buildReviewsUrl('987654', 3);

        expect($url)->toBe('https://www.zooplus.co.uk/reviews/987654?page=3');
    });
});
