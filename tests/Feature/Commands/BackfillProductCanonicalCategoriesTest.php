<?php

declare(strict_types=1);

use App\Enums\CanonicalCategory;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('BackfillProductCanonicalCategories Command', function () {
    it('backfills canonical categories for all products', function () {
        Product::factory()->create([
            'name' => 'Premium Dry Dog Food',
            'category' => 'Dry Food',
            'canonical_category' => null,
        ]);

        Product::factory()->create([
            'name' => 'Puppy Complete Nutrition',
            'category' => 'Puppy Food',
            'canonical_category' => null,
        ]);

        Product::factory()->create([
            'name' => 'Dental Sticks',
            'category' => 'Dental',
            'canonical_category' => null,
        ]);

        $this->artisan('products:backfill-canonical-categories --force')
            ->expectsOutput('Found 3 products to process.')
            ->assertSuccessful();

        $products = Product::all();

        expect($products[0]->canonical_category)->toBe(CanonicalCategory::DryFood);
        expect($products[1]->canonical_category)->toBe(CanonicalCategory::PuppyFood);
        expect($products[2]->canonical_category)->toBe(CanonicalCategory::Dental);
    });

    it('skips products with no change', function () {
        Product::factory()->create([
            'name' => 'Premium Dry Dog Food',
            'category' => 'Dry Food',
            'canonical_category' => CanonicalCategory::DryFood,
        ]);

        $this->artisan('products:backfill-canonical-categories --force')
            ->expectsOutput('Found 1 products to process.')
            ->assertSuccessful();

        expect(Product::first()->canonical_category)->toBe(CanonicalCategory::DryFood);
    });

    it('uses title context when category is not conclusive', function () {
        Product::factory()->create([
            'name' => 'Puppy Training Treats',
            'category' => 'Dog Food',
            'canonical_category' => null,
        ]);

        $this->artisan('products:backfill-canonical-categories --force')
            ->assertSuccessful();

        expect(Product::first()->canonical_category)->toBe(CanonicalCategory::Treats);
    });

    it('handles products with null category', function () {
        Product::factory()->create([
            'name' => 'Senior Dog Food',
            'category' => null,
            'canonical_category' => null,
        ]);

        $this->artisan('products:backfill-canonical-categories --force')
            ->assertSuccessful();

        expect(Product::first()->canonical_category)->toBe(CanonicalCategory::SeniorFood);
    });

    it('processes products in chunks', function () {
        Product::factory()->count(150)->create([
            'category' => 'Dry Food',
            'canonical_category' => null,
        ]);

        $this->artisan('products:backfill-canonical-categories --force --chunk=50')
            ->expectsOutput('Found 150 products to process.')
            ->assertSuccessful();

        expect(Product::where('canonical_category', CanonicalCategory::DryFood)->count())->toBe(150);
    });

    it('shows message when no products exist', function () {
        $this->artisan('products:backfill-canonical-categories --force')
            ->expectsOutput('No products found to backfill.')
            ->assertSuccessful();
    });

    it('requires confirmation when force flag is not set', function () {
        Product::factory()->create([
            'category' => 'Dry Food',
            'canonical_category' => null,
        ]);

        $this->artisan('products:backfill-canonical-categories')
            ->expectsConfirmation('Do you want to continue?', 'no')
            ->expectsOutput('Operation cancelled.')
            ->assertSuccessful();

        expect(Product::first()->canonical_category)->toBeNull();
    });
});
