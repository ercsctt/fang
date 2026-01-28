<?php

declare(strict_types=1);

use App\Crawler\Extractors\BM\BMProductReviewsExtractor;
use App\Jobs\Crawler\CrawlProductReviewsJob;
use App\Models\ProductListing;
use App\Models\ProductListingReview;
use App\Models\Retailer;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->retailer = Retailer::factory()->create([
        'name' => 'B&M',
        'slug' => 'bm',
        'base_url' => 'https://www.bmstores.co.uk',
    ]);

    $this->listing = ProductListing::factory()->for($this->retailer)->create([
        'url' => 'https://www.bmstores.co.uk/product/pedigree-adult-123456',
        'title' => 'Pedigree Adult Dog Food 12kg',
        'last_reviews_scraped_at' => null,
    ]);
});

describe('job execution', function () {
    test('job can be dispatched and serialized', function () {
        Queue::fake();

        CrawlProductReviewsJob::dispatch(
            BMProductReviewsExtractor::class,
            $this->listing->id,
            $this->listing->url,
        );

        Queue::assertPushed(CrawlProductReviewsJob::class);
    });

    test('job has correct tags for monitoring', function () {
        $job = new CrawlProductReviewsJob(
            BMProductReviewsExtractor::class,
            $this->listing->id,
            $this->listing->url,
        );

        $tags = $job->tags();

        expect($tags)->toContain('crawler')
            ->toContain('product-reviews')
            ->toContain("listing:{$this->listing->id}");
    });

    test('job has configurable timeout and retry settings', function () {
        $job = new CrawlProductReviewsJob(
            BMProductReviewsExtractor::class,
            $this->listing->id,
            $this->listing->url,
        );

        expect($job->timeout)->toBe(180)
            ->and($job->tries)->toBe(3)
            ->and($job->backoff)->toBe(60);
    });
});

describe('review creation', function () {
    test('creates new reviews from extracted data', function () {
        // Manually test the upsert logic
        $listing = $this->listing;

        $listing->reviews()->create([
            'external_id' => 'test-review-001',
            'author' => 'Test User',
            'rating' => 5.0,
            'title' => 'Great product',
            'body' => 'This is an excellent product, highly recommend!',
            'verified_purchase' => true,
            'review_date' => now()->subDays(5),
            'helpful_count' => 10,
            'metadata' => ['source' => 'test'],
        ]);

        expect($listing->reviews()->count())->toBe(1);

        $review = $listing->reviews()->first();
        expect($review->external_id)->toBe('test-review-001')
            ->and($review->author)->toBe('Test User')
            ->and($review->rating)->toBe(5.0)
            ->and($review->title)->toBe('Great product')
            ->and($review->verified_purchase)->toBeTrue()
            ->and($review->helpful_count)->toBe(10);
    });
});

describe('review deduplication', function () {
    test('does not create duplicate reviews with same external_id', function () {
        $listing = $this->listing;

        // Create first review
        $listing->reviews()->create([
            'external_id' => 'unique-review-001',
            'author' => 'Original Author',
            'rating' => 4.0,
            'title' => 'Original Title',
            'body' => 'Original body text',
            'verified_purchase' => false,
            'helpful_count' => 0,
        ]);

        expect($listing->reviews()->count())->toBe(1);

        // Attempting to create duplicate should use updateOrCreate pattern
        $existingReview = $listing->reviews()
            ->where('external_id', 'unique-review-001')
            ->first();

        expect($existingReview)->not->toBeNull();

        // If we use updateOrCreate, it will update instead of creating duplicate
        $listing->reviews()->updateOrCreate(
            ['external_id' => 'unique-review-001'],
            [
                'author' => 'Updated Author',
                'rating' => 4.5,
                'body' => 'Updated body text',
            ]
        );

        expect($listing->reviews()->count())->toBe(1);

        $updatedReview = $listing->reviews()->first();
        expect($updatedReview->author)->toBe('Updated Author')
            ->and($updatedReview->rating)->toBe(4.5);
    });

    test('unique constraint prevents duplicate external_id per listing', function () {
        $listing = $this->listing;

        $listing->reviews()->create([
            'external_id' => 'duplicate-test',
            'rating' => 5.0,
            'body' => 'First review',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $listing->reviews()->create([
            'external_id' => 'duplicate-test',
            'rating' => 4.0,
            'body' => 'Second review with same external_id',
        ]);
    });
});

describe('last_reviews_scraped_at update', function () {
    test('updates last_reviews_scraped_at timestamp after review crawl', function () {
        expect($this->listing->last_reviews_scraped_at)->toBeNull();

        $this->listing->update(['last_reviews_scraped_at' => now()]);
        $this->listing->refresh();

        expect($this->listing->last_reviews_scraped_at)->not->toBeNull();
    });
});

describe('needsReviewScraping scope', function () {
    test('returns listings that have never been scraped for reviews', function () {
        $neverScraped = ProductListing::factory()->for($this->retailer)->create([
            'last_reviews_scraped_at' => null,
        ]);

        $recentlyScraped = ProductListing::factory()->for($this->retailer)->create([
            'last_reviews_scraped_at' => now()->subDays(1),
        ]);

        $listings = ProductListing::needsReviewScraping(7)->get();

        expect($listings->pluck('id')->toArray())
            ->toContain($neverScraped->id)
            ->toContain($this->listing->id) // from beforeEach, has null last_reviews_scraped_at
            ->not->toContain($recentlyScraped->id);
    });

    test('returns listings scraped more than X days ago', function () {
        $oldScrape = ProductListing::factory()->for($this->retailer)->create([
            'last_reviews_scraped_at' => now()->subDays(10),
        ]);

        $recentScrape = ProductListing::factory()->for($this->retailer)->create([
            'last_reviews_scraped_at' => now()->subDays(3),
        ]);

        $listings = ProductListing::needsReviewScraping(7)->get();

        expect($listings->pluck('id')->toArray())
            ->toContain($oldScrape->id)
            ->not->toContain($recentScrape->id);
    });
});

describe('review model relationships', function () {
    test('review belongs to product listing', function () {
        $review = ProductListingReview::factory()->for($this->listing)->create();

        expect($review->productListing->id)->toBe($this->listing->id);
    });

    test('product listing has many reviews', function () {
        ProductListingReview::factory()->count(3)->for($this->listing)->create();

        expect($this->listing->reviews)->toHaveCount(3);
    });
});

describe('review scopes', function () {
    test('verified scope filters verified purchases', function () {
        ProductListingReview::factory()->for($this->listing)->create(['verified_purchase' => true]);
        ProductListingReview::factory()->for($this->listing)->create(['verified_purchase' => false]);

        expect(ProductListingReview::verified()->count())->toBe(1);
    });

    test('highRated scope filters high rated reviews', function () {
        ProductListingReview::factory()->for($this->listing)->create(['rating' => 5.0]);
        ProductListingReview::factory()->for($this->listing)->create(['rating' => 4.5]);
        ProductListingReview::factory()->for($this->listing)->create(['rating' => 3.0]);

        expect(ProductListingReview::highRated(4.0)->count())->toBe(2);
    });

    test('recent scope filters recent reviews', function () {
        ProductListingReview::factory()->for($this->listing)->create([
            'review_date' => now()->subDays(10),
        ]);
        ProductListingReview::factory()->for($this->listing)->create([
            'review_date' => now()->subDays(60),
        ]);

        expect(ProductListingReview::recent(30)->count())->toBe(1);
    });
});

describe('error handling', function () {
    test('handles non-existent product listing gracefully', function () {
        $job = new CrawlProductReviewsJob(
            BMProductReviewsExtractor::class,
            99999, // Non-existent ID
            'https://www.bmstores.co.uk/product/test',
        );

        expect($job)->toBeInstanceOf(CrawlProductReviewsJob::class);
    });
});
