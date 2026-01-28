<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductListing;
use Illuminate\Support\Str;

describe('slug auto-generation in boot', function () {
    test('generates slug from name when slug is empty', function () {
        $product = Product::factory()->create([
            'name' => 'Pedigree Chicken Dog Food',
            'slug' => null,
        ]);

        expect($product->slug)->toBe('pedigree-chicken-dog-food');
    });

    test('generates slug from name when slug is empty string', function () {
        $product = Product::factory()->create([
            'name' => 'Royal Canin Adult',
            'slug' => '',
        ]);

        expect($product->slug)->toBe('royal-canin-adult');
    });

    test('does not override existing slug', function () {
        $product = Product::factory()->create([
            'name' => 'Pedigree Chicken Dog Food',
            'slug' => 'custom-slug',
        ]);

        expect($product->slug)->toBe('custom-slug');
    });

    test('handles special characters in name', function () {
        $product = Product::factory()->create([
            'name' => "Lily's Kitchen Beef & Vegetables",
            'slug' => null,
        ]);

        expect($product->slug)->toBe('lilys-kitchen-beef-vegetables');
    });

    test('handles multiple spaces in name', function () {
        $product = Product::factory()->create([
            'name' => 'Pedigree   Complete   Food',
            'slug' => null,
        ]);

        expect($product->slug)->toBe(Str::slug('Pedigree   Complete   Food'));
    });
});

describe('price formatted accessors', function () {
    test('lowest_price_formatted returns formatted price', function () {
        $product = Product::factory()->make([
            'lowest_price_pence' => 1299,
        ]);

        expect($product->lowest_price_formatted)->toBe('£12.99');
    });

    test('lowest_price_formatted returns null when price is null', function () {
        $product = Product::factory()->make([
            'lowest_price_pence' => null,
        ]);

        expect($product->lowest_price_formatted)->toBeNull();
    });

    test('lowest_price_formatted formats zero correctly', function () {
        $product = Product::factory()->make([
            'lowest_price_pence' => 0,
        ]);

        expect($product->lowest_price_formatted)->toBe('£0.00');
    });

    test('average_price_formatted returns formatted price', function () {
        $product = Product::factory()->make([
            'average_price_pence' => 2499,
        ]);

        expect($product->average_price_formatted)->toBe('£24.99');
    });

    test('average_price_formatted returns null when price is null', function () {
        $product = Product::factory()->make([
            'average_price_pence' => null,
        ]);

        expect($product->average_price_formatted)->toBeNull();
    });

    test('formats large prices correctly', function () {
        $product = Product::factory()->make([
            'lowest_price_pence' => 12345,
            'average_price_pence' => 15000,
        ]);

        expect($product->lowest_price_formatted)->toBe('£123.45')
            ->and($product->average_price_formatted)->toBe('£150.00');
    });
});

describe('relationships through pivot', function () {
    test('has many product listings through pivot', function () {
        $product = Product::factory()->create();
        $listings = ProductListing::factory()->count(3)->create();

        foreach ($listings as $listing) {
            $product->productListings()->attach($listing->id, [
                'confidence_score' => 95.0,
                'match_type' => 'exact',
                'matched_at' => now(),
            ]);
        }

        expect($product->productListings)->toHaveCount(3)
            ->and($product->productListings->first())->toBeInstanceOf(ProductListing::class);
    });

    test('pivot table includes timestamps', function () {
        $product = Product::factory()->create();
        $listing = ProductListing::factory()->create();

        $product->productListings()->attach($listing->id, [
            'confidence_score' => 95.0,
            'match_type' => 'exact',
            'matched_at' => now(),
        ]);

        $pivot = $product->productListings->first()->pivot;

        expect($pivot->created_at)->not->toBeNull()
            ->and($pivot->updated_at)->not->toBeNull();
    });
});

describe('casts', function () {
    test('is_verified is cast to boolean', function () {
        $product = Product::factory()->verified()->create();

        expect($product->is_verified)->toBeBool()
            ->and($product->is_verified)->toBeTrue();
    });

    test('metadata is cast to array', function () {
        $product = Product::factory()->create([
            'metadata' => ['life_stage' => 'Adult', 'dog_size' => 'Medium'],
        ]);

        expect($product->metadata)->toBeArray()
            ->and($product->metadata['life_stage'])->toBe('Adult')
            ->and($product->metadata['dog_size'])->toBe('Medium');
    });

    test('weight_grams is cast to integer', function () {
        $product = Product::factory()->create(['weight_grams' => 2500]);

        expect($product->weight_grams)->toBeInt()
            ->and($product->weight_grams)->toBe(2500);
    });

    test('quantity is cast to integer', function () {
        $product = Product::factory()->create(['quantity' => 12]);

        expect($product->quantity)->toBeInt()
            ->and($product->quantity)->toBe(12);
    });

    test('average_price_pence is cast to integer', function () {
        $product = Product::factory()->create(['average_price_pence' => 1299]);

        expect($product->average_price_pence)->toBeInt()
            ->and($product->average_price_pence)->toBe(1299);
    });

    test('lowest_price_pence is cast to integer', function () {
        $product = Product::factory()->create(['lowest_price_pence' => 999]);

        expect($product->lowest_price_pence)->toBeInt()
            ->and($product->lowest_price_pence)->toBe(999);
    });
});

describe('factory states', function () {
    test('verified state sets is_verified to true', function () {
        $product = Product::factory()->verified()->create();

        expect($product->is_verified)->toBeTrue();
    });

    test('unverified state sets is_verified to false', function () {
        $product = Product::factory()->unverified()->create();

        expect($product->is_verified)->toBeFalse();
    });
});
