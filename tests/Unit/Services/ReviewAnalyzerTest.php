<?php

declare(strict_types=1);

use App\Enums\Sentiment;
use App\Models\ProductListing;
use App\Models\ProductListingReview;
use App\Services\ReviewAnalyzer;

beforeEach(function () {
    $this->analyzer = new ReviewAnalyzer;
});

describe('analyze', function () {
    test('identifies positive sentiment from positive keywords', function () {
        $review = ProductListingReview::factory()->make([
            'title' => 'Amazing product!',
            'body' => 'My dog absolutely loves this food. It\'s excellent quality and great value.',
            'rating' => 5.0,
        ]);

        $result = $this->analyzer->analyze($review);

        expect($result['sentiment'])->toBe(Sentiment::Positive)
            ->and($result['score'])->toBeGreaterThan(0.3)
            ->and($result['positive_keywords'])->not->toBeEmpty();
    });

    test('identifies negative sentiment from negative keywords', function () {
        $review = ProductListingReview::factory()->make([
            'title' => 'Terrible experience',
            'body' => 'My dog refused to eat this and got sick. Waste of money.',
            'rating' => 1.0,
        ]);

        $result = $this->analyzer->analyze($review);

        expect($result['sentiment'])->toBe(Sentiment::Negative)
            ->and($result['score'])->toBeLessThan(-0.3)
            ->and($result['negative_keywords'])->not->toBeEmpty();
    });

    test('identifies neutral sentiment from mixed content', function () {
        $review = ProductListingReview::factory()->make([
            'title' => 'Okay product',
            'body' => 'It\'s decent. My dog eats it but nothing special.',
            'rating' => 3.0,
        ]);

        $result = $this->analyzer->analyze($review);

        expect($result['sentiment'])->toBe(Sentiment::Neutral)
            ->and($result['score'])->toBeGreaterThanOrEqual(-0.3)
            ->and($result['score'])->toBeLessThanOrEqual(0.3);
    });

    test('handles negation of positive words', function () {
        $review = ProductListingReview::factory()->make([
            'title' => null,
            'body' => 'My dog does not love this food.',
            'rating' => 2.0,
        ]);

        $result = $this->analyzer->analyze($review);

        // "not love" should be negative
        expect($result['negative_keywords'])->toContain('not love');
    });

    test('handles negation of negative words', function () {
        $review = ProductListingReview::factory()->make([
            'title' => null,
            'body' => 'This product is not bad at all.',
            'rating' => 4.0,
        ]);

        $result = $this->analyzer->analyze($review);

        // "not bad" should contribute positively
        expect($result['positive_keywords'])->toContain('not bad');
    });

    test('applies intensifiers to sentiment scores', function () {
        $normalReview = ProductListingReview::factory()->make([
            'title' => null,
            'body' => 'This food is good.',
            'rating' => 4.0,
        ]);

        $intensifiedReview = ProductListingReview::factory()->make([
            'title' => null,
            'body' => 'This food is very good.',
            'rating' => 4.0,
        ]);

        $normalResult = $this->analyzer->analyze($normalReview);
        $intensifiedResult = $this->analyzer->analyze($intensifiedReview);

        expect($intensifiedResult['score'])->toBeGreaterThanOrEqual($normalResult['score']);
    });

    test('detects positive phrases', function () {
        $review = ProductListingReview::factory()->make([
            'title' => null,
            'body' => 'I would highly recommend this product. Great value for money.',
            'rating' => 5.0,
        ]);

        $result = $this->analyzer->analyze($review);

        expect($result['positive_keywords'])->toContain('highly recommend');
    });

    test('detects negative phrases', function () {
        $review = ProductListingReview::factory()->make([
            'title' => null,
            'body' => 'This is a complete waste of money. My dog won\'t eat it.',
            'rating' => 1.0,
        ]);

        $result = $this->analyzer->analyze($review);

        expect($result['negative_keywords'])->toContain('waste of money');
    });

    test('identifies praise topics', function () {
        $review = ProductListingReview::factory()->make([
            'title' => null,
            'body' => 'My dog loves the taste and his coat is now so shiny and healthy.',
            'rating' => 5.0,
        ]);

        $result = $this->analyzer->analyze($review);

        expect($result['topics']['praises'])->toContain('taste')
            ->and($result['topics']['praises'])->toContain('health');
    });

    test('identifies complaint topics', function () {
        $review = ProductListingReview::factory()->make([
            'title' => null,
            'body' => 'My dog got sick after eating this and it was overpriced.',
            'rating' => 1.0,
        ]);

        $result = $this->analyzer->analyze($review);

        expect($result['topics']['complaints'])->toContain('health')
            ->and($result['topics']['complaints'])->toContain('value');
    });

    test('calculates confidence based on signals', function () {
        $reviewWithRating = ProductListingReview::factory()->make([
            'body' => 'Great food!',
            'rating' => 5.0,
        ]);

        $reviewWithoutRating = ProductListingReview::factory()->make([
            'body' => 'Great food!',
            'rating' => null,
        ]);

        $resultWithRating = $this->analyzer->analyze($reviewWithRating);
        $resultWithoutRating = $this->analyzer->analyze($reviewWithoutRating);

        expect($resultWithRating['confidence'])->toBeGreaterThan($resultWithoutRating['confidence']);
    });

    test('handles empty review body', function () {
        $review = ProductListingReview::factory()->make([
            'title' => null,
            'body' => '',
            'rating' => 4.0,
        ]);

        $result = $this->analyzer->analyze($review);

        // Should rely on rating only
        expect($result['sentiment'])->toBe(Sentiment::Positive)
            ->and($result['positive_keywords'])->toBeEmpty()
            ->and($result['negative_keywords'])->toBeEmpty();
    });

    test('handles review with title only', function () {
        $review = ProductListingReview::factory()->make([
            'title' => 'Excellent product!',
            'body' => null,
            'rating' => 5.0,
        ]);

        $result = $this->analyzer->analyze($review);

        expect($result['sentiment'])->toBe(Sentiment::Positive)
            ->and($result['positive_keywords'])->toContain('excellent');
    });

    test('returns score within valid range', function () {
        $reviews = [
            ProductListingReview::factory()->make(['body' => 'Amazing excellent wonderful!', 'rating' => 5.0]),
            ProductListingReview::factory()->make(['body' => 'Terrible awful horrible!', 'rating' => 1.0]),
            ProductListingReview::factory()->make(['body' => 'Okay decent fine.', 'rating' => 3.0]),
        ];

        foreach ($reviews as $review) {
            $result = $this->analyzer->analyze($review);

            expect($result['score'])->toBeGreaterThanOrEqual(-1.0)
                ->and($result['score'])->toBeLessThanOrEqual(1.0);
        }
    });

    test('pet-specific keywords are detected', function () {
        $positiveReview = ProductListingReview::factory()->make([
            'body' => 'My dog devours this food and gobbles it up every time.',
            'rating' => 5.0,
        ]);

        $negativeReview = ProductListingReview::factory()->make([
            'body' => 'My dog had diarrhea and started vomiting.',
            'rating' => 1.0,
        ]);

        $positiveResult = $this->analyzer->analyze($positiveReview);
        $negativeResult = $this->analyzer->analyze($negativeReview);

        expect($positiveResult['positive_keywords'])->toContain('devours')
            ->and($negativeResult['negative_keywords'])->toContain('diarrhea');
    });
});

describe('analyzeMultiple', function () {
    test('calculates average score correctly', function () {
        $reviews = [
            ProductListingReview::factory()->make(['body' => 'Love it!', 'rating' => 5.0]),
            ProductListingReview::factory()->make(['body' => 'Hate it!', 'rating' => 1.0]),
        ];

        $result = $this->analyzer->analyzeMultiple($reviews);

        expect($result['total_reviews'])->toBe(2)
            ->and($result['average_score'])->toBeGreaterThan(-1.0)
            ->and($result['average_score'])->toBeLessThan(1.0);
    });

    test('calculates sentiment distribution', function () {
        $reviews = [
            ProductListingReview::factory()->make(['body' => 'Amazing!', 'rating' => 5.0]),
            ProductListingReview::factory()->make(['body' => 'Great!', 'rating' => 5.0]),
            ProductListingReview::factory()->make(['body' => 'Okay.', 'rating' => 3.0]),
            ProductListingReview::factory()->make(['body' => 'Terrible!', 'rating' => 1.0]),
        ];

        $result = $this->analyzer->analyzeMultiple($reviews);

        expect($result['sentiment_distribution']['positive'])->toBe(2)
            ->and($result['sentiment_distribution']['neutral'])->toBe(1)
            ->and($result['sentiment_distribution']['negative'])->toBe(1);
    });

    test('returns empty results for empty input', function () {
        $result = $this->analyzer->analyzeMultiple([]);

        expect($result['total_reviews'])->toBe(0)
            ->and($result['average_score'])->toBe(0.0)
            ->and($result['common_praises'])->toBe([])
            ->and($result['common_complaints'])->toBe([]);
    });

    test('aggregates common praises across reviews', function () {
        $reviews = [
            ProductListingReview::factory()->make(['body' => 'My dog loves the taste!', 'rating' => 5.0]),
            ProductListingReview::factory()->make(['body' => 'Tasty food, my dog enjoys it.', 'rating' => 5.0]),
            ProductListingReview::factory()->make(['body' => 'Great value for money.', 'rating' => 4.0]),
        ];

        $result = $this->analyzer->analyzeMultiple($reviews);

        expect($result['common_praises'])->toHaveKey('taste');
    });

    test('aggregates common complaints across reviews', function () {
        $reviews = [
            ProductListingReview::factory()->make(['body' => 'My dog got sick.', 'rating' => 1.0]),
            ProductListingReview::factory()->make(['body' => 'Made my dog vomit.', 'rating' => 1.0]),
            ProductListingReview::factory()->make(['body' => 'Way too expensive.', 'rating' => 2.0]),
        ];

        $result = $this->analyzer->analyzeMultiple($reviews);

        expect($result['common_complaints'])->toHaveKey('health');
    });

    test('limits top keywords to 10', function () {
        // Create many reviews with various keywords
        $reviews = [];
        for ($i = 0; $i < 20; $i++) {
            $reviews[] = ProductListingReview::factory()->make([
                'body' => 'Love great excellent amazing wonderful fantastic good nice quality value fresh healthy recommend tasty',
                'rating' => 5.0,
            ]);
        }

        $result = $this->analyzer->analyzeMultiple($reviews);

        expect(count($result['top_positive_keywords']))->toBeLessThanOrEqual(10);
    });
});

describe('analyzeProductListing', function () {
    test('analyzes all reviews for a product listing', function () {
        $listing = ProductListing::factory()->create();
        ProductListingReview::factory()->count(3)->create([
            'product_listing_id' => $listing->id,
            'body' => 'Great product!',
            'rating' => 5.0,
        ]);

        $result = $this->analyzer->analyzeProductListing($listing);

        expect($result['total_reviews'])->toBe(3)
            ->and($result['sentiment_distribution']['positive'])->toBe(3);
    });

    test('returns empty results for listing with no reviews', function () {
        $listing = ProductListing::factory()->create();

        $result = $this->analyzer->analyzeProductListing($listing);

        expect($result['total_reviews'])->toBe(0);
    });
});

describe('generateSummary', function () {
    test('generates summary for positive reviews', function () {
        $listing = ProductListing::factory()->create();
        ProductListingReview::factory()->count(5)->create([
            'product_listing_id' => $listing->id,
            'body' => 'My dog loves this food. Great taste and quality!',
            'rating' => 5.0,
        ]);

        $summary = $this->analyzer->generateSummary($listing);

        expect($summary)->toContain('5 reviews')
            ->and($summary)->toContain('pleased');
    });

    test('generates summary for negative reviews', function () {
        $listing = ProductListing::factory()->create();
        ProductListingReview::factory()->count(3)->create([
            'product_listing_id' => $listing->id,
            'body' => 'My dog got sick. Terrible product!',
            'rating' => 1.0,
        ]);

        $summary = $this->analyzer->generateSummary($listing);

        expect($summary)->toContain('3 reviews')
            ->and($summary)->toContain('concerns');
    });

    test('returns message for listing with no reviews', function () {
        $listing = ProductListing::factory()->create();

        $summary = $this->analyzer->generateSummary($listing);

        expect($summary)->toBe('No reviews available for this product.');
    });

    test('includes praise topics in summary', function () {
        $listing = ProductListing::factory()->create();
        ProductListingReview::factory()->count(3)->create([
            'product_listing_id' => $listing->id,
            'body' => 'Great value for money and excellent quality.',
            'rating' => 5.0,
        ]);

        $summary = $this->analyzer->generateSummary($listing);

        expect($summary)->toContain('praise');
    });

    test('includes complaint topics in summary', function () {
        $listing = ProductListing::factory()->create();
        ProductListingReview::factory()->create([
            'product_listing_id' => $listing->id,
            'body' => 'Way too expensive and made my dog sick.',
            'rating' => 1.0,
        ]);

        $summary = $this->analyzer->generateSummary($listing);

        expect($summary)->toContain('mentioned');
    });

    test('uses singular review for single review', function () {
        $listing = ProductListing::factory()->create();
        ProductListingReview::factory()->create([
            'product_listing_id' => $listing->id,
            'body' => 'Good food!',
            'rating' => 4.0,
        ]);

        $summary = $this->analyzer->generateSummary($listing);

        expect($summary)->toContain('1 review');
    });
});

describe('Sentiment enum', function () {
    test('fromScore returns correct sentiment', function () {
        expect(Sentiment::fromScore(0.5))->toBe(Sentiment::Positive)
            ->and(Sentiment::fromScore(0.0))->toBe(Sentiment::Neutral)
            ->and(Sentiment::fromScore(-0.5))->toBe(Sentiment::Negative);
    });

    test('boundary values are correct', function () {
        expect(Sentiment::fromScore(0.3))->toBe(Sentiment::Positive)
            ->and(Sentiment::fromScore(0.29))->toBe(Sentiment::Neutral)
            ->and(Sentiment::fromScore(-0.3))->toBe(Sentiment::Negative)
            ->and(Sentiment::fromScore(-0.29))->toBe(Sentiment::Neutral);
    });
});
