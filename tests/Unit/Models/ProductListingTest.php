<?php

declare(strict_types=1);

use App\Models\ProductListing;
use App\Models\ProductListingPrice;
use App\Models\ProductListingReview;
use App\Models\Retailer;

beforeEach(function () {
    $this->retailer = Retailer::factory()->create();
});

describe('scopes', function () {
    test('inStock scope returns only in-stock listings', function () {
        ProductListing::factory()->for($this->retailer)->inStock()->count(3)->create();
        ProductListing::factory()->for($this->retailer)->outOfStock()->count(2)->create();

        $inStockListings = ProductListing::query()->inStock()->get();

        expect($inStockListings)->toHaveCount(3)
            ->and($inStockListings->every(fn ($listing) => $listing->in_stock))->toBeTrue();
    });

    test('onSale scope returns only on-sale listings', function () {
        ProductListing::factory()->for($this->retailer)->onSale()->count(2)->create();
        ProductListing::factory()->for($this->retailer)->count(3)->create([
            'original_price_pence' => null,
        ]);

        $onSaleListings = ProductListing::query()->onSale()->get();

        expect($onSaleListings)->toHaveCount(2)
            ->and($onSaleListings->every(fn ($listing) => $listing->original_price_pence !== null && $listing->price_pence < $listing->original_price_pence))->toBeTrue();
    });

    test('byRetailer scope filters by retailer id', function () {
        $otherRetailer = Retailer::factory()->create();
        ProductListing::factory()->for($this->retailer)->count(3)->create();
        ProductListing::factory()->for($otherRetailer)->count(2)->create();

        $listings = ProductListing::query()->byRetailer($this->retailer->id)->get();

        expect($listings)->toHaveCount(3)
            ->and($listings->every(fn ($listing) => $listing->retailer_id === $this->retailer->id))->toBeTrue();
    });

    test('byBrand scope filters by brand', function () {
        ProductListing::factory()->for($this->retailer)->count(2)->create(['brand' => 'Pedigree']);
        ProductListing::factory()->for($this->retailer)->count(3)->create(['brand' => 'Royal Canin']);

        $listings = ProductListing::query()->byBrand('Pedigree')->get();

        expect($listings)->toHaveCount(2)
            ->and($listings->every(fn ($listing) => $listing->brand === 'Pedigree'))->toBeTrue();
    });

    test('byCategory scope filters by category', function () {
        ProductListing::factory()->for($this->retailer)->count(2)->create(['category' => 'Dog Food']);
        ProductListing::factory()->for($this->retailer)->count(3)->create(['category' => 'Cat Food']);

        $listings = ProductListing::query()->byCategory('Dog Food')->get();

        expect($listings)->toHaveCount(2)
            ->and($listings->every(fn ($listing) => $listing->category === 'Dog Food'))->toBeTrue();
    });

    test('needsScraping scope returns listings not scraped within hours', function () {
        ProductListing::factory()->for($this->retailer)->needsScraping()->count(2)->create();
        ProductListing::factory()->for($this->retailer)->recentlyScraped()->count(3)->create();
        ProductListing::factory()->for($this->retailer)->count(1)->create(['last_scraped_at' => null]);

        $needsScraping = ProductListing::query()->needsScraping(24)->get();

        expect($needsScraping)->toHaveCount(3); // 2 old + 1 null
    });

    test('needsScraping scope respects custom hours parameter', function () {
        ProductListing::factory()->for($this->retailer)->create([
            'last_scraped_at' => now()->subHours(5),
        ]);
        ProductListing::factory()->for($this->retailer)->create([
            'last_scraped_at' => now()->subHours(3),
        ]);

        expect(ProductListing::query()->needsScraping(6)->count())->toBe(0)
            ->and(ProductListing::query()->needsScraping(4)->count())->toBe(1)
            ->and(ProductListing::query()->needsScraping(2)->count())->toBe(2);
    });
});

describe('recordPrice method', function () {
    test('creates price record when no previous price exists', function () {
        $listing = ProductListing::factory()->for($this->retailer)->create([
            'price_pence' => 1299,
            'original_price_pence' => 1599,
            'currency' => 'GBP',
        ]);

        $listing->recordPrice();

        expect($listing->prices()->count())->toBe(1)
            ->and($listing->prices()->first()->price_pence)->toBe(1299)
            ->and($listing->prices()->first()->original_price_pence)->toBe(1599)
            ->and($listing->prices()->first()->currency)->toBe('GBP');
    });

    test('creates price record when price has changed', function () {
        $listing = ProductListing::factory()->for($this->retailer)->create([
            'price_pence' => 1299,
            'currency' => 'GBP',
        ]);

        ProductListingPrice::factory()->for($listing)->create([
            'price_pence' => 1099,
            'recorded_at' => now()->subDay(),
        ]);

        $listing->recordPrice();

        expect($listing->prices()->count())->toBe(2)
            ->and($listing->prices()->latest('recorded_at')->first()->price_pence)->toBe(1299);
    });

    test('does not create price record when price has not changed', function () {
        $listing = ProductListing::factory()->for($this->retailer)->create([
            'price_pence' => 1299,
            'currency' => 'GBP',
        ]);

        ProductListingPrice::factory()->for($listing)->create([
            'price_pence' => 1299,
            'recorded_at' => now()->subDay(),
        ]);

        $listing->recordPrice();

        expect($listing->prices()->count())->toBe(1);
    });
});

describe('isOnSale helper', function () {
    test('returns true when price is less than original price', function () {
        $listing = ProductListing::factory()->for($this->retailer)->make([
            'price_pence' => 1299,
            'original_price_pence' => 1599,
        ]);

        expect($listing->isOnSale())->toBeTrue();
    });

    test('returns false when original price is null', function () {
        $listing = ProductListing::factory()->for($this->retailer)->make([
            'price_pence' => 1299,
            'original_price_pence' => null,
        ]);

        expect($listing->isOnSale())->toBeFalse();
    });

    test('returns false when price equals original price', function () {
        $listing = ProductListing::factory()->for($this->retailer)->make([
            'price_pence' => 1299,
            'original_price_pence' => 1299,
        ]);

        expect($listing->isOnSale())->toBeFalse();
    });

    test('returns false when price is greater than original price', function () {
        $listing = ProductListing::factory()->for($this->retailer)->make([
            'price_pence' => 1599,
            'original_price_pence' => 1299,
        ]);

        expect($listing->isOnSale())->toBeFalse();
    });

    test('returns false when price is null', function () {
        $listing = ProductListing::factory()->for($this->retailer)->make([
            'price_pence' => null,
            'original_price_pence' => 1599,
        ]);

        expect($listing->isOnSale())->toBeFalse();
    });
});

describe('relationships', function () {
    test('belongs to retailer', function () {
        $listing = ProductListing::factory()->for($this->retailer)->create();

        expect($listing->retailer)->toBeInstanceOf(Retailer::class)
            ->and($listing->retailer->id)->toBe($this->retailer->id);
    });

    test('has many prices', function () {
        $listing = ProductListing::factory()->for($this->retailer)->create();
        ProductListingPrice::factory()->for($listing)->count(3)->create();

        expect($listing->prices)->toHaveCount(3)
            ->and($listing->prices->first())->toBeInstanceOf(ProductListingPrice::class);
    });

    test('has many reviews', function () {
        $listing = ProductListing::factory()->for($this->retailer)->create();
        ProductListingReview::factory()->for($listing)->count(5)->create();

        expect($listing->reviews)->toHaveCount(5)
            ->and($listing->reviews->first())->toBeInstanceOf(ProductListingReview::class);
    });
});

describe('price formatted accessor', function () {
    test('formats price in pounds', function () {
        $listing = ProductListing::factory()->for($this->retailer)->make([
            'price_pence' => 1299,
        ]);

        expect($listing->price_formatted)->toBe('£12.99');
    });

    test('formats zero price', function () {
        $listing = ProductListing::factory()->for($this->retailer)->make([
            'price_pence' => 0,
        ]);

        expect($listing->price_formatted)->toBe('£0.00');
    });

    test('returns null when price is null', function () {
        $listing = ProductListing::factory()->for($this->retailer)->make([
            'price_pence' => null,
        ]);

        expect($listing->price_formatted)->toBeNull();
    });

    test('formats large prices correctly', function () {
        $listing = ProductListing::factory()->for($this->retailer)->make([
            'price_pence' => 12345,
        ]);

        expect($listing->price_formatted)->toBe('£123.45');
    });
});
