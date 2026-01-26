<?php

declare(strict_types=1);

use App\Crawler\DTOs\ProductReview;
use App\Crawler\Extractors\Amazon\AmazonProductReviewsExtractor;

beforeEach(function () {
    $this->extractor = new AmazonProductReviewsExtractor;
});

describe('canHandle', function () {
    test('returns true for amazon.co.uk /dp/ product URLs', function () {
        expect($this->extractor->canHandle('https://www.amazon.co.uk/dp/B08L5WRMZJ'))
            ->toBeTrue();
    });

    test('returns true for amazon.co.uk product-reviews pages', function () {
        expect($this->extractor->canHandle('https://www.amazon.co.uk/product-reviews/B08L5WRMZJ'))
            ->toBeTrue();
    });

    test('returns true for amazon.co.uk customer-reviews pages', function () {
        expect($this->extractor->canHandle('https://www.amazon.co.uk/customer-reviews/B08L5WRMZJ'))
            ->toBeTrue();
    });

    test('returns false for amazon.co.uk search pages', function () {
        expect($this->extractor->canHandle('https://www.amazon.co.uk/s?k=dog+food'))
            ->toBeFalse();
    });

    test('returns false for amazon.com (non-UK)', function () {
        expect($this->extractor->canHandle('https://www.amazon.com/dp/B08L5WRMZJ'))
            ->toBeFalse();
    });
});

describe('review extraction', function () {
    test('extracts reviews from DOM with data-hook attribute', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-hook="review" id="R123ABC">
                <i data-hook="review-star-rating">
                    <span class="a-icon-alt">4.0 out of 5 stars</span>
                </i>
                <span data-hook="review-title">Great product!</span>
                <span data-hook="review-body"><span>My dog loves this food. Highly recommended.</span></span>
                <span class="a-profile-name">John Doe</span>
                <span data-hook="review-date">Reviewed in the United Kingdom on 15 January 2024</span>
                <span data-hook="avp-badge">Verified Purchase</span>
                <span data-hook="helpful-vote-statement">10 people found this helpful</span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1)
            ->and($results[0])->toBeInstanceOf(ProductReview::class)
            ->and($results[0]->rating)->toBe(4.0)
            ->and($results[0]->body)->toBe('My dog loves this food. Highly recommended.')
            ->and($results[0]->author)->toBe('John Doe')
            ->and($results[0]->verifiedPurchase)->toBeTrue()
            ->and($results[0]->helpfulCount)->toBe(10);
    });

    test('extracts review title', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-hook="review" id="R123ABC">
                <i data-hook="review-star-rating">
                    <span class="a-icon-alt">5.0 out of 5 stars</span>
                </i>
                <span data-hook="review-title"><span>Excellent quality</span></span>
                <span data-hook="review-body"><span>This is great dog food.</span></span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->title)->toBe('Excellent quality');
    });

    test('extracts review date', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-hook="review" id="R123ABC">
                <i data-hook="review-star-rating">
                    <span class="a-icon-alt">4.0 out of 5 stars</span>
                </i>
                <span data-hook="review-body"><span>Good food.</span></span>
                <span data-hook="review-date">Reviewed in the United Kingdom on 25 December 2024</span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->reviewDate)->not->toBeNull()
            ->and($results[0]->reviewDate->format('Y-m-d'))->toBe('2024-12-25');
    });

    test('extracts multiple reviews', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-hook="review" id="R111">
                <i data-hook="review-star-rating"><span class="a-icon-alt">5.0 out of 5 stars</span></i>
                <span data-hook="review-body"><span>Review 1 body</span></span>
            </div>
            <div data-hook="review" id="R222">
                <i data-hook="review-star-rating"><span class="a-icon-alt">4.0 out of 5 stars</span></i>
                <span data-hook="review-body"><span>Review 2 body</span></span>
            </div>
            <div data-hook="review" id="R333">
                <i data-hook="review-star-rating"><span class="a-icon-alt">3.0 out of 5 stars</span></i>
                <span data-hook="review-body"><span>Review 3 body</span></span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(3);
    });

    test('uses review ID as external ID', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-hook="review" id="R1A2B3C4D5E6F7G8H">
                <i data-hook="review-star-rating"><span class="a-icon-alt">5.0 out of 5 stars</span></i>
                <span data-hook="review-body"><span>Great!</span></span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->toBe('R1A2B3C4D5E6F7G8H');
    });

    test('handles "One person found this helpful"', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-hook="review" id="R123ABC">
                <i data-hook="review-star-rating"><span class="a-icon-alt">5.0 out of 5 stars</span></i>
                <span data-hook="review-body"><span>Good food.</span></span>
                <span data-hook="helpful-vote-statement">One person found this helpful</span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->helpfulCount)->toBe(1);
    });
});

describe('rating extraction', function () {
    it('extracts various rating formats', function (string $ratingText, float $expectedRating) {
        $html = <<<HTML
        <html>
        <body>
            <div data-hook="review" id="R123ABC">
                <i data-hook="review-star-rating">
                    <span class="a-icon-alt">{$ratingText}</span>
                </i>
                <span data-hook="review-body"><span>Test review.</span></span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->rating)->toBe($expectedRating);
    })->with([
        'five stars' => ['5.0 out of 5 stars', 5.0],
        'four stars' => ['4.0 out of 5 stars', 4.0],
        'three stars' => ['3.0 out of 5 stars', 3.0],
        'half star' => ['4.5 out of 5 stars', 4.5],
        'one star' => ['1.0 out of 5 stars', 1.0],
    ]);
});

describe('verified purchase detection', function () {
    test('detects verified purchase badge', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-hook="review" id="R123ABC">
                <i data-hook="review-star-rating"><span class="a-icon-alt">5.0 out of 5 stars</span></i>
                <span data-hook="review-body"><span>Test.</span></span>
                <span data-hook="avp-badge">Verified Purchase</span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->verifiedPurchase)->toBeTrue();
    });

    test('defaults to false when no verified badge', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-hook="review" id="R123ABC">
                <i data-hook="review-star-rating"><span class="a-icon-alt">5.0 out of 5 stars</span></i>
                <span data-hook="review-body"><span>Test.</span></span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->verifiedPurchase)->toBeFalse();
    });
});

describe('metadata', function () {
    test('includes source information in metadata', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-hook="review" id="R123ABC">
                <i data-hook="review-star-rating"><span class="a-icon-alt">5.0 out of 5 stars</span></i>
                <span data-hook="review-body"><span>Test.</span></span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->metadata)->toHaveKey('source_url')
            ->and($results[0]->metadata['source_url'])->toBe($url)
            ->and($results[0]->metadata)->toHaveKey('retailer')
            ->and($results[0]->metadata['retailer'])->toBe('amazon-uk')
            ->and($results[0]->metadata)->toHaveKey('extracted_at');
    });
});

describe('reviews URL builder', function () {
    test('builds correct reviews page URL', function () {
        $url = AmazonProductReviewsExtractor::buildReviewsUrl('B08L5WRMZJ');

        expect($url)->toContain('amazon.co.uk/product-reviews/B08L5WRMZJ')
            ->and($url)->toContain('pageNumber=1')
            ->and($url)->toContain('sortBy=recent');
    });

    test('builds URL with custom page number', function () {
        $url = AmazonProductReviewsExtractor::buildReviewsUrl('B08L5WRMZJ', 3);

        expect($url)->toContain('pageNumber=3');
    });
});

describe('CAPTCHA detection', function () {
    test('yields nothing when CAPTCHA detected', function () {
        $html = <<<'HTML'
        <html>
        <head><title>Sorry!</title></head>
        <body>
            <div class="captcha">Enter the characters</div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });
});

describe('edge cases', function () {
    test('handles page with no reviews', function () {
        $html = '<html><body><div id="reviews">No reviews yet.</div></body></html>';
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('skips reviews without rating', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-hook="review" id="R123ABC">
                <span data-hook="review-body"><span>Review without rating.</span></span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('skips reviews without body text', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-hook="review" id="R123ABC">
                <i data-hook="review-star-rating"><span class="a-icon-alt">5.0 out of 5 stars</span></i>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toBeEmpty();
    });

    test('generates unique ID when not provided', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-hook="review">
                <i data-hook="review-star-rating"><span class="a-icon-alt">5.0 out of 5 stars</span></i>
                <span data-hook="review-body"><span>Test review content.</span></span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results[0]->externalId)->not->toBeEmpty()
            ->and(strlen($results[0]->externalId))->toBe(32); // MD5 hash length
    });

    test('handles malformed HTML gracefully', function () {
        $html = '<html><body><div data-hook="review" id="R123"><i data-hook="review-star-rating"><span class="a-icon-alt">4.0 out of 5 stars</span></i><span data-hook="review-body"><span>Test</span></body>';
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));

        expect($results)->toHaveCount(1);
    });
});

describe('DTO conversion', function () {
    test('review DTO can be converted to array', function () {
        $html = <<<'HTML'
        <html>
        <body>
            <div data-hook="review" id="R123ABC">
                <i data-hook="review-star-rating"><span class="a-icon-alt">5.0 out of 5 stars</span></i>
                <span data-hook="review-body"><span>Great product!</span></span>
                <span class="a-profile-name">Test User</span>
            </div>
        </body>
        </html>
        HTML;
        $url = 'https://www.amazon.co.uk/dp/B08L5WRMZJ';

        $results = iterator_to_array($this->extractor->extract($html, $url));
        $array = $results[0]->toArray();

        expect($array)->toHaveKey('external_id')
            ->and($array)->toHaveKey('rating')
            ->and($array)->toHaveKey('body')
            ->and($array)->toHaveKey('author')
            ->and($array)->toHaveKey('verified_purchase');
    });
});
