<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductReview;
use App\Crawler\Extractors\BM\BMProductReviewsExtractor;

beforeEach(function () {
    $this->extractor = new BMProductReviewsExtractor;
});

describe('canHandle', function () {
    test('returns true for bmstores.co.uk product URLs with /product/ pattern', function () {
        expect($this->extractor->canHandle('https://www.bmstores.co.uk/product/pedigree-dog-food-123456'))
            ->toBeTrue();
    });

    test('returns true for bmstores.co.uk product URLs with /p/{number} pattern', function () {
        expect($this->extractor->canHandle('https://www.bmstores.co.uk/p/123456'))
            ->toBeTrue();
    });

    test('returns true for bmstores.co.uk product URLs with /pd/{slug} pattern', function () {
        expect($this->extractor->canHandle('https://www.bmstores.co.uk/pd/pedigree-dog-food'))
            ->toBeTrue();
    });

    test('returns false for bmstores.co.uk non-product URLs', function () {
        expect($this->extractor->canHandle('https://www.bmstores.co.uk/pets/dog-food'))
            ->toBeFalse();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/product/123456'))
            ->toBeFalse();
    });
});

describe('JSON-LD extraction', function () {
    test('extracts reviews from JSON-LD structured data', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/bm-product-page-with-reviews.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(3)
            ->and($results[0])->toBeInstanceOf(ProductReview::class);
    });

    test('extracts review rating from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/bm-product-page-with-reviews.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->rating)->toBe(5.0)
            ->and($results[1]->rating)->toBe(4.0)
            ->and($results[2]->rating)->toBe(4.5);
    });

    test('extracts review author from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/bm-product-page-with-reviews.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->author)->toBe('John D.')
            ->and($results[1]->author)->toBe('Sarah M.')
            ->and($results[2]->author)->toBe('Anonymous');
    });

    test('extracts review body from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/bm-product-page-with-reviews.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->body)->toContain('My dog absolutely loves this food');
    });

    test('extracts review title from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/bm-product-page-with-reviews.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Excellent dog food')
            ->and($results[1]->title)->toBe('Good quality')
            ->and($results[2]->title)->toBeNull();
    });

    test('extracts review date from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/bm-product-page-with-reviews.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->reviewDate)->not->toBeNull()
            ->and($results[0]->reviewDate->format('Y-m-d'))->toBe('2024-01-15');
    });

    test('extracts verified purchase status from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/bm-product-page-with-reviews.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->verifiedPurchase)->toBeTrue()
            ->and($results[1]->verifiedPurchase)->toBeFalse();
    });

    test('extracts helpful count from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/bm-product-page-with-reviews.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[2]->helpfulCount)->toBe(5);
    });

    test('extracts external ID from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/bm-product-page-with-reviews.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('review-001')
            ->and($results[1]->externalId)->toBe('review-002')
            ->and($results[2]->externalId)->toBe('review-003');
    });
});

describe('DOM extraction fallback', function () {
    test('extracts reviews from DOM when no JSON-LD present', function () {
        $html = '
            <html>
            <body>
                <div class="review-item" data-review-id="dom-review-1">
                    <span class="review-author">Test User</span>
                    <div class="review-rating" data-rating="4"></div>
                    <h3 class="review-title">Great product</h3>
                    <p class="review-body">This is an excellent product, highly recommend!</p>
                    <time datetime="2024-02-01">1 Feb 2024</time>
                </div>
            </body>
            </html>
        ';
        $url = 'https://www.bmstores.co.uk/product/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->author)->toBe('Test User')
            ->and($results[0]->rating)->toBe(4.0)
            ->and($results[0]->title)->toBe('Great product')
            ->and($results[0]->body)->toContain('excellent product')
            ->and($results[0]->externalId)->toBe('dom-review-1');
    });

    test('extracts rating from star count', function () {
        $html = '
            <html>
            <body>
                <div class="review-item">
                    <div class="rating">
                        <span class="star-filled"></span>
                        <span class="star-filled"></span>
                        <span class="star-filled"></span>
                        <span class="star-filled"></span>
                        <span class="star-empty"></span>
                    </div>
                    <p class="review-body">Good product</p>
                </div>
            </body>
            </html>
        ';
        $url = 'https://www.bmstores.co.uk/product/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->rating)->toBe(4.0);
    });

    test('extracts rating from text format', function () {
        $html = '
            <html>
            <body>
                <div class="review-item">
                    <span class="rating">4.5 out of 5 stars</span>
                    <p class="review-body">Good product</p>
                </div>
            </body>
            </html>
        ';
        $url = 'https://www.bmstores.co.uk/product/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->rating)->toBe(4.5);
    });

    test('detects verified purchase from DOM element', function () {
        $html = '
            <html>
            <body>
                <div class="review-item">
                    <span class="review-rating" data-rating="5"></span>
                    <span class="verified-purchase">Verified Purchase</span>
                    <p class="review-body">Amazing product</p>
                </div>
            </body>
            </html>
        ';
        $url = 'https://www.bmstores.co.uk/product/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->verifiedPurchase)->toBeTrue();
    });

    test('extracts helpful count from DOM', function () {
        $html = '
            <html>
            <body>
                <div class="review-item">
                    <span class="review-rating" data-rating="5"></span>
                    <p class="review-body">Great product</p>
                    <span class="helpful-count">12 people found this helpful</span>
                </div>
            </body>
            </html>
        ';
        $url = 'https://www.bmstores.co.uk/product/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->helpfulCount)->toBe(12);
    });
});

describe('edge cases', function () {
    test('returns empty generator for page without reviews', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/bm-product-page.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('skips reviews without rating', function () {
        $html = '
            <html>
            <body>
                <div class="review-item">
                    <p class="review-body">No rating on this review</p>
                </div>
            </body>
            </html>
        ';
        $url = 'https://www.bmstores.co.uk/product/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('skips reviews without body text', function () {
        $html = '
            <html>
            <body>
                <div class="review-item">
                    <span class="review-rating" data-rating="5"></span>
                </div>
            </body>
            </html>
        ';
        $url = 'https://www.bmstores.co.uk/product/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles malformed JSON-LD gracefully', function () {
        $html = '
            <html>
            <head>
                <script type="application/ld+json">
                { invalid json here }
                </script>
            </head>
            <body>
                <div class="review-item">
                    <span class="review-rating" data-rating="5"></span>
                    <p class="review-body">Fallback review</p>
                </div>
            </body>
            </html>
        ';
        $url = 'https://www.bmstores.co.uk/product/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        // Should fall back to DOM extraction
        expect($results)->toHaveCount(1)
            ->and($results[0]->body)->toBe('Fallback review');
    });

    test('generates unique external IDs when not provided', function () {
        $html = '
            <html>
            <body>
                <div class="review-item">
                    <span class="review-rating" data-rating="5"></span>
                    <p class="review-body">First review without ID</p>
                </div>
                <div class="review-item">
                    <span class="review-rating" data-rating="4"></span>
                    <p class="review-body">Second review without ID</p>
                </div>
            </body>
            </html>
        ';
        $url = 'https://www.bmstores.co.uk/product/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(2)
            ->and($results[0]->externalId)->not->toBeEmpty()
            ->and($results[1]->externalId)->not->toBeEmpty()
            ->and($results[0]->externalId)->not->toBe($results[1]->externalId);
    });

    test('includes metadata with source information', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/bm-product-page-with-reviews.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('source')
            ->and($results[0]->metadata['source'])->toBe('json-ld')
            ->and($results[0]->metadata)->toHaveKey('source_url')
            ->and($results[0]->metadata['source_url'])->toBe($url)
            ->and($results[0]->metadata)->toHaveKey('extracted_at');
    });
});

describe('DTO conversion', function () {
    test('review DTO can be converted to array', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/bm-product-page-with-reviews.html');
        $url = 'https://www.bmstores.co.uk/product/pedigree-adult-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $array = $results[0]->toArray();

        expect($array)->toHaveKey('external_id')
            ->and($array)->toHaveKey('rating')
            ->and($array)->toHaveKey('author')
            ->and($array)->toHaveKey('title')
            ->and($array)->toHaveKey('body')
            ->and($array)->toHaveKey('verified_purchase')
            ->and($array)->toHaveKey('review_date')
            ->and($array)->toHaveKey('helpful_count')
            ->and($array)->toHaveKey('metadata');
    });
});
