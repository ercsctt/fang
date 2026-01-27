<?php

declare(strict_types=1);

use App\Enums\MatchType;
use App\Jobs\Crawler\MatchProductListingJob;
use App\Models\Product;
use App\Models\ProductListing;
use App\Models\ProductListingMatch;
use App\Models\Retailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->retailer = Retailer::factory()->create();
});

describe('job execution', function () {
    test('creates match when product exists', function () {
        $product = Product::factory()->create([
            'name' => 'Pedigree Chicken Dog Food',
            'brand' => 'Pedigree',
            'weight_grams' => 2000,
            'quantity' => 1,
            'canonical_category' => \App\Enums\CanonicalCategory::DryFood,
        ]);

        $listing = ProductListing::factory()->create([
            'retailer_id' => $this->retailer->id,
            'title' => 'Pedigree Chicken Dog Food',
            'brand' => 'Pedigree',
            'weight_grams' => 2000,
            'quantity' => 1,
            'category' => 'Dry Food',
        ]);

        $job = new MatchProductListingJob($listing->id);
        $job->handle(app(\App\Services\ProductMatcher::class));

        $match = ProductListingMatch::query()
            ->where('product_listing_id', $listing->id)
            ->first();

        expect($match)->not->toBeNull()
            ->and($match->product_id)->toBe($product->id)
            ->and($match->match_type)->toBe(MatchType::Exact);
    });

    test('creates new product when no match and createProductIfNoMatch is true', function () {
        $listing = ProductListing::factory()->create([
            'retailer_id' => $this->retailer->id,
            'title' => 'Unique Brand Specialty Food',
            'brand' => 'Unique Brand',
            'weight_grams' => 2000,
            'price_pence' => 1999,
        ]);

        $productCountBefore = Product::count();

        $job = new MatchProductListingJob($listing->id, createProductIfNoMatch: true);
        $job->handle(app(\App\Services\ProductMatcher::class));

        expect(Product::count())->toBe($productCountBefore + 1);

        $match = ProductListingMatch::query()
            ->where('product_listing_id', $listing->id)
            ->first();

        expect($match)->not->toBeNull();

        $newProduct = $match->product;
        expect($newProduct->name)->toBe('Unique Brand Specialty Food')
            ->and($newProduct->brand)->toBe('Unique Brand');
    });

    test('does not create product when createProductIfNoMatch is false', function () {
        $listing = ProductListing::factory()->create([
            'retailer_id' => $this->retailer->id,
            'title' => 'Another Unique Brand Food',
            'brand' => 'Another Unique Brand',
        ]);

        $productCountBefore = Product::count();

        $job = new MatchProductListingJob($listing->id, createProductIfNoMatch: false);
        $job->handle(app(\App\Services\ProductMatcher::class));

        expect(Product::count())->toBe($productCountBefore);

        $match = ProductListingMatch::query()
            ->where('product_listing_id', $listing->id)
            ->first();

        expect($match)->toBeNull();
    });

    test('skips processing when listing not found', function () {
        $job = new MatchProductListingJob(999999);
        $job->handle(app(\App\Services\ProductMatcher::class));

        // Should complete without exception
        expect(ProductListingMatch::count())->toBe(0);
    });

    test('skips processing when listing has empty title', function () {
        $listing = ProductListing::factory()->create([
            'retailer_id' => $this->retailer->id,
            'title' => '',
        ]);

        $job = new MatchProductListingJob($listing->id);
        $job->handle(app(\App\Services\ProductMatcher::class));

        $match = ProductListingMatch::query()
            ->where('product_listing_id', $listing->id)
            ->first();

        expect($match)->toBeNull();
    });
});

describe('job dispatch', function () {
    test('can be dispatched to queue', function () {
        Queue::fake();

        $listing = ProductListing::factory()->create([
            'retailer_id' => $this->retailer->id,
            'title' => 'Test Product',
        ]);

        MatchProductListingJob::dispatch($listing->id);

        Queue::assertPushed(MatchProductListingJob::class, function ($job) {
            return true; // Just verify it was pushed
        });
    });

    test('has correct tags for monitoring', function () {
        $listing = ProductListing::factory()->create([
            'retailer_id' => $this->retailer->id,
            'title' => 'Test Product',
        ]);

        $job = new MatchProductListingJob($listing->id);

        expect($job->tags())->toContain('product-matching')
            ->and($job->tags())->toContain('listing:'.$listing->id);
    });
});

describe('idempotency', function () {
    test('does not create duplicate matches on multiple runs', function () {
        $product = Product::factory()->create([
            'name' => 'Pedigree Chicken Dog Food',
            'brand' => 'Pedigree',
            'weight_grams' => 2000,
            'quantity' => 1,
        ]);

        $listing = ProductListing::factory()->create([
            'retailer_id' => $this->retailer->id,
            'title' => 'Pedigree Chicken Dog Food',
            'brand' => 'Pedigree',
            'weight_grams' => 2000,
            'quantity' => 1,
        ]);

        // Run job twice
        $job = new MatchProductListingJob($listing->id);
        $job->handle(app(\App\Services\ProductMatcher::class));
        $job->handle(app(\App\Services\ProductMatcher::class));

        $matchCount = ProductListingMatch::query()
            ->where('product_listing_id', $listing->id)
            ->count();

        expect($matchCount)->toBe(1);
    });
});
