<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductReview;
use App\Crawler\Extractors\Sainsburys\SainsburysProductReviewsExtractor;

beforeEach(function () {
    $this->extractor = new SainsburysProductReviewsExtractor;
});

describe('canHandle', function () {
    test('returns true for gol-ui product URLs', function () {
        expect($this->extractor->canHandle('https://www.sainsburys.co.uk/gol-ui/product/pedigree-vital-protection-adult--7878567'))
            ->toBeTrue();
    });

    test('returns true for alternative product URL format', function () {
        expect($this->extractor->canHandle('https://www.sainsburys.co.uk/product/royal-canin-medium-adult-1234567'))
            ->toBeTrue();
    });

    test('returns true for shop/gb/groceries product URLs', function () {
        expect($this->extractor->canHandle('https://www.sainsburys.co.uk/shop/gb/groceries/dog-food/pedigree-chicken--7878567'))
            ->toBeTrue();
    });

    test('returns false for category pages', function () {
        expect($this->extractor->canHandle('https://www.sainsburys.co.uk/shop/gb/groceries/pets/dog'))
            ->toBeFalse();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/product/test-123456'))
            ->toBeFalse();
    });
});

describe('JSON-LD extraction', function () {
    test('extracts reviews from JSON-LD structured data', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/sainsburys-product-page-with-reviews.html');
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/pedigree-adult--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(3)
            ->and($results[0])->toBeInstanceOf(ProductReview::class);
    });

    test('extracts review rating from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/sainsburys-product-page-with-reviews.html');
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/pedigree-adult--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->rating)->toBe(5.0)
            ->and($results[1]->rating)->toBe(4.0)
            ->and($results[2]->rating)->toBe(3.0);
    });

    test('extracts review author from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/sainsburys-product-page-with-reviews.html');
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/pedigree-adult--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->author)->toBe('HappyDogOwner')
            ->and($results[1]->author)->toBe('PetParent')
            ->and($results[2]->author)->toBe('Anonymous');
    });

    test('extracts review body from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/sainsburys-product-page-with-reviews.html');
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/pedigree-adult--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->body)->toContain('My labrador loves this food');
    });

    test('extracts review title from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/sainsburys-product-page-with-reviews.html');
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/pedigree-adult--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Great value food')
            ->and($results[1]->title)->toBe('Good everyday food');
    });

    test('extracts review date from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/sainsburys-product-page-with-reviews.html');
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/pedigree-adult--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->reviewDate)->not->toBeNull()
            ->and($results[0]->reviewDate->format('Y-m-d'))->toBe('2024-02-10');
    });

    test('extracts verified purchase status from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/sainsburys-product-page-with-reviews.html');
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/pedigree-adult--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->verifiedPurchase)->toBeTrue()
            ->and($results[1]->verifiedPurchase)->toBeFalse();
    });

    test('extracts external ID from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/sainsburys-product-page-with-reviews.html');
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/pedigree-adult--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('sainsburys-review-001')
            ->and($results[1]->externalId)->toBe('sainsburys-review-002')
            ->and($results[2]->externalId)->toBe('sainsburys-review-003');
    });
});

describe('DOM extraction fallback', function () {
    test('extracts reviews from DOM when no JSON-LD present', function () {
        $html = <<<'HTML'
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
        HTML;
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/test--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->author)->toBe('Test User')
            ->and($results[0]->rating)->toBe(4.0)
            ->and($results[0]->title)->toBe('Great product')
            ->and($results[0]->body)->toContain('excellent product')
            ->and($results[0]->externalId)->toBe('dom-review-1');
    });

    test('extracts reviews from Sainsburys-specific selectors', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-test-id="review-item">
                <span data-test-id="review-author">Reviewer123</span>
                <div data-test-id="review-rating" data-rating="5"></div>
                <h4 data-test-id="review-title">Excellent!</h4>
                <p data-test-id="review-body">Best dog food I've ever bought</p>
                <time data-test-id="review-date" datetime="2024-01-15">15 Jan 2024</time>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/test--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->author)->toBe('Reviewer123')
            ->and($results[0]->rating)->toBe(5.0)
            ->and($results[0]->title)->toBe('Excellent!')
            ->and($results[0]->body)->toBe("Best dog food I've ever bought");
    });

    test('extracts rating from aria-label', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item">
                <div class="rating" aria-label="4.5 out of 5 stars"></div>
                <p class="review-body">Good quality product</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/test--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->rating)->toBe(4.5);
    });

    test('extracts rating from star count', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item">
                <div class="stars">
                    <span class="star-filled"></span>
                    <span class="star-filled"></span>
                    <span class="star-filled"></span>
                    <span class="star-filled"></span>
                    <span class="star-empty"></span>
                </div>
                <p class="review-body">Good product, my dog loves it</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/test--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->rating)->toBe(4.0);
    });

    test('detects verified purchase from DOM element', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item">
                <span class="review-rating" data-rating="5"></span>
                <span class="verified-purchase">Verified Purchase</span>
                <p class="review-body">Amazing product that my pet loves!</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/test--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->verifiedPurchase)->toBeTrue();
    });

    test('extracts helpful count from DOM', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item">
                <span class="review-rating" data-rating="5"></span>
                <p class="review-body">Great product!</p>
                <span class="helpful-count" data-helpful-count="25"></span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/test--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->helpfulCount)->toBe(25);
    });

    test('extracts reviews from Bazaarvoice widget', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="bv-content-review">
                <span class="bv-author">BVUser</span>
                <div class="bv-rating-ratio-number" data-rating="4"></div>
                <h4 class="bv-content-title">Quality product</h4>
                <p class="bv-content-summary-body-text">My dog absolutely loves this food</p>
                <time class="bv-content-datetime" datetime="2024-01-20">20 Jan 2024</time>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/test--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->author)->toBe('BVUser')
            ->and($results[0]->rating)->toBe(4.0)
            ->and($results[0]->title)->toBe('Quality product');
    });
});

describe('edge cases', function () {
    test('returns empty generator for page without reviews', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/sainsburys-product-page.html');
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/pedigree-adult--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('skips reviews without rating', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item">
                <p class="review-body">No rating on this review</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/test--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('skips reviews without body text', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item">
                <span class="review-rating" data-rating="5"></span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/test--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('handles malformed JSON-LD gracefully', function () {
        $html = <<<'HTML'
        <html>
        <head>
            <script type="application/ld+json">
            { invalid json here }
            </script>
        </head>
        <body>
            <div class="review-item">
                <span class="review-rating" data-rating="5"></span>
                <p class="review-body">Fallback review from DOM</p>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/test--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->body)->toBe('Fallback review from DOM');
    });

    test('generates unique external IDs when not provided', function () {
        $html = <<<'HTML'
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
        HTML;
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/test--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(2)
            ->and($results[0]->externalId)->not->toBeEmpty()
            ->and($results[1]->externalId)->not->toBeEmpty()
            ->and($results[0]->externalId)->not->toBe($results[1]->externalId);
    });

    test('includes metadata with source information', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/sainsburys-product-page-with-reviews.html');
        $url = 'https://www.sainsburys.co.uk/gol-ui/product/pedigree-adult--123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('source')
            ->and($results[0]->metadata['source'])->toBe('json-ld')
            ->and($results[0]->metadata)->toHaveKey('extracted_at')
            ->and($results[0]->metadata)->toHaveKey('source_url');
    });
});

describe('buildReviewsUrl', function () {
    test('builds review page URL for a product', function () {
        $url = SainsburysProductReviewsExtractor::buildReviewsUrl('test-product--123456');

        expect($url)->toBe('https://www.sainsburys.co.uk/gol-ui/product/test-product--123456/reviews?page=1');
    });

    test('builds review page URL with pagination', function () {
        $url = SainsburysProductReviewsExtractor::buildReviewsUrl('test-product--123456', 3);

        expect($url)->toBe('https://www.sainsburys.co.uk/gol-ui/product/test-product--123456/reviews?page=3');
    });
});
