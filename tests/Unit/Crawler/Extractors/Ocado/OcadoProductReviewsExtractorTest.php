<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductReview;
use App\Crawler\Extractors\Ocado\OcadoProductReviewsExtractor;

beforeEach(function () {
    $this->extractor = new OcadoProductReviewsExtractor;
});

describe('canHandle', function () {
    test('returns true for ocado.com product URLs', function () {
        expect($this->extractor->canHandle('https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890'))
            ->toBeTrue();
    });

    test('returns true for product URLs without www prefix', function () {
        expect($this->extractor->canHandle('https://ocado.com/products/pedigree-chicken-123456'))
            ->toBeTrue();
    });

    test('returns true for review page URLs', function () {
        expect($this->extractor->canHandle('https://www.ocado.com/products/test-123456/reviews/'))
            ->toBeTrue();
    });

    test('returns true for customer-reviews page URLs', function () {
        expect($this->extractor->canHandle('https://www.ocado.com/products/test-123456/customer-reviews/'))
            ->toBeTrue();
    });

    test('returns false for browse/category pages', function () {
        expect($this->extractor->canHandle('https://www.ocado.com/browse/pets-20974/dog-111797'))
            ->toBeFalse();
    });

    test('returns false for search pages', function () {
        expect($this->extractor->canHandle('https://www.ocado.com/search?entry=dog%20food'))
            ->toBeFalse();
    });

    test('returns false for other domains', function () {
        expect($this->extractor->canHandle('https://www.tesco.com/products/test-123456'))
            ->toBeFalse();
    });
});

describe('JSON-LD extraction', function () {
    test('extracts reviews from JSON-LD structured data', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page-with-reviews.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(3)
            ->and($results[0])->toBeInstanceOf(ProductReview::class);
    });

    test('extracts review rating from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page-with-reviews.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->rating)->toBe(5.0)
            ->and($results[1]->rating)->toBe(4.0)
            ->and($results[2]->rating)->toBe(5.0);
    });

    test('extracts review author from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page-with-reviews.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->author)->toBe('SmallDogOwner')
            ->and($results[1]->author)->toBe('DogMum2023')
            ->and($results[2]->author)->toBe('PetLover456');
    });

    test('extracts review body from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page-with-reviews.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->body)->toContain('My Chihuahua loves this food');
    });

    test('extracts review title from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page-with-reviews.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Perfect for my Chihuahua!')
            ->and($results[1]->title)->toBe('Good quality food');
    });

    test('extracts review date from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page-with-reviews.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->reviewDate)->not->toBeNull()
            ->and($results[0]->reviewDate->format('Y-m-d'))->toBe('2024-01-20');
    });

    test('extracts verified purchase status from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page-with-reviews.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->verifiedPurchase)->toBeTrue()
            ->and($results[1]->verifiedPurchase)->toBeFalse()
            ->and($results[2]->verifiedPurchase)->toBeTrue();
    });

    test('extracts external ID from JSON-LD', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page-with-reviews.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('ocado-review-001')
            ->and($results[1]->externalId)->toBe('ocado-review-002')
            ->and($results[2]->externalId)->toBe('ocado-review-003');
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
        $url = 'https://www.ocado.com/products/test-product-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->author)->toBe('Test User')
            ->and($results[0]->rating)->toBe(4.0)
            ->and($results[0]->title)->toBe('Great product')
            ->and($results[0]->body)->toContain('excellent product')
            ->and($results[0]->externalId)->toBe('dom-review-1');
    });

    test('extracts reviews from Ocado-specific selectors', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-testid="review-item">
                <span data-testid="review-author">Reviewer123</span>
                <div data-testid="review-rating" data-rating="5"></div>
                <h4 data-testid="review-title">Excellent!</h4>
                <p data-testid="review-body">Best dog food I've ever bought</p>
                <time data-testid="review-date" datetime="2024-01-15">15 Jan 2024</time>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.ocado.com/products/test-product-123456';

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
        $url = 'https://www.ocado.com/products/test-product-123456';

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
        $url = 'https://www.ocado.com/products/test-product-123456';

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
        $url = 'https://www.ocado.com/products/test-product-123456';

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
                <p class="review-body">Great product for dogs!</p>
                <span class="helpful-count">25 people found this helpful</span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.ocado.com/products/test-product-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->helpfulCount)->toBe(25);
    });

    test('extracts date from text content', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div class="review-item">
                <span class="review-rating" data-rating="4"></span>
                <p class="review-body">My dog loves this food!</p>
                <span class="review-date">15 January 2024</span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.ocado.com/products/test-product-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0]->reviewDate)->not->toBeNull()
            ->and($results[0]->reviewDate->format('Y-m-d'))->toBe('2024-01-15');
    });
});

describe('blocked page detection', function () {
    test('returns empty for blocked/captcha page', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-blocked-page.html');
        $url = 'https://www.ocado.com/products/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('detects robot check from page content', function () {
        $html = '<html><body><p>robot check required</p></body></html>';
        $url = 'https://www.ocado.com/products/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('detects blocked page from title', function () {
        $html = '<html><head><title>Blocked</title></head><body></body></html>';
        $url = 'https://www.ocado.com/products/test-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });
});

describe('edge cases', function () {
    test('returns empty generator for page without reviews', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

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
        $url = 'https://www.ocado.com/products/test-product-123456';

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
        $url = 'https://www.ocado.com/products/test-product-123456';

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
        $url = 'https://www.ocado.com/products/test-product-123456';

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
        $url = 'https://www.ocado.com/products/test-product-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(2)
            ->and($results[0]->externalId)->not->toBeEmpty()
            ->and($results[1]->externalId)->not->toBeEmpty()
            ->and($results[0]->externalId)->not->toBe($results[1]->externalId);
    });

    test('includes metadata with source information', function () {
        $html = file_get_contents(__DIR__.'/../../../../Fixtures/ocado-product-page-with-reviews.html');
        $url = 'https://www.ocado.com/products/royal-canin-mini-adult-dog-food-2kg-567890';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('source')
            ->and($results[0]->metadata['source'])->toBe('json-ld')
            ->and($results[0]->metadata)->toHaveKey('extracted_at')
            ->and($results[0]->metadata)->toHaveKey('retailer')
            ->and($results[0]->metadata['retailer'])->toBe('ocado');
    });

    test('handles empty HTML gracefully', function () {
        $html = '<html><body></body></html>';
        $url = 'https://www.ocado.com/products/test-product-123456';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });
});
