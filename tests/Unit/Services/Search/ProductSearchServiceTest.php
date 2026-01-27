<?php

use App\Enums\CanonicalCategory;
use App\Models\Product;
use App\Models\ProductListing;
use App\Models\ProductListingMatch;
use App\Models\Retailer;
use App\Services\Search\ProductSearchFilters;
use App\Services\Search\ProductSearchService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

describe('ProductSearchService', function () {
    describe('search', function () {
        it('searches products by name', function () {
            Product::factory()->create(['name' => 'Premium Dog Food Alpha']);
            Product::factory()->create(['name' => 'Budget Cat Food Beta']);

            $service = new ProductSearchService;
            $filters = new ProductSearchFilters(query: 'Premium Dog');

            $results = $service->search($filters);

            expect($results)->toHaveCount(1);
            expect($results->first()->name)->toBe('Premium Dog Food Alpha');
        });

        it('searches products by brand', function () {
            Product::factory()->create(['name' => 'Some Food', 'brand' => 'Pedigree']);
            Product::factory()->create(['name' => 'Other Food', 'brand' => 'Bakers']);

            $service = new ProductSearchService;
            $filters = new ProductSearchFilters(query: 'Pedigree');

            $results = $service->search($filters);

            expect($results)->toHaveCount(1);
            expect($results->first()->brand)->toBe('Pedigree');
        });

        it('searches products by description', function () {
            Product::factory()->create([
                'name' => 'Dog Food A',
                'description' => 'Contains premium chicken protein',
            ]);
            Product::factory()->create([
                'name' => 'Dog Food B',
                'description' => 'Contains beef and vegetables',
            ]);

            $service = new ProductSearchService;
            $filters = new ProductSearchFilters(query: 'chicken');

            $results = $service->search($filters);

            expect($results)->toHaveCount(1);
        });

        it('filters by brand', function () {
            Product::factory()->create(['name' => 'Chicken Food', 'brand' => 'Pedigree']);
            Product::factory()->create(['name' => 'Chicken Treats', 'brand' => 'Bakers']);

            $service = new ProductSearchService;
            $filters = new ProductSearchFilters(query: 'Chicken', brand: 'Pedigree');

            $results = $service->search($filters);

            expect($results)->toHaveCount(1);
            expect($results->first()->brand)->toBe('Pedigree');
        });

        it('filters by category', function () {
            Product::factory()->create(['name' => 'Premium Food A', 'category' => 'Dog Food']);
            Product::factory()->create(['name' => 'Premium Food B', 'category' => 'Cat Food']);

            $service = new ProductSearchService;
            $filters = new ProductSearchFilters(query: 'Premium', category: 'Dog Food');

            $results = $service->search($filters);

            expect($results)->toHaveCount(1);
            expect($results->first()->category)->toBe('Dog Food');
        });

        it('filters by canonical category', function () {
            Product::factory()->create([
                'name' => 'Premium Food A',
                'canonical_category' => CanonicalCategory::DryFood,
            ]);
            Product::factory()->create([
                'name' => 'Premium Food B',
                'canonical_category' => CanonicalCategory::WetFood,
            ]);

            $service = new ProductSearchService;
            $filters = new ProductSearchFilters(
                query: 'Premium',
                canonicalCategory: CanonicalCategory::DryFood
            );

            $results = $service->search($filters);

            expect($results)->toHaveCount(1);
            expect($results->first()->canonical_category)->toBe(CanonicalCategory::DryFood);
        });

        it('filters by price range', function () {
            Product::factory()->create(['name' => 'Cheap Food', 'lowest_price_pence' => 500]);
            Product::factory()->create(['name' => 'Mid Food', 'lowest_price_pence' => 1500]);
            Product::factory()->create(['name' => 'Expensive Food', 'lowest_price_pence' => 3000]);

            $service = new ProductSearchService;
            $filters = new ProductSearchFilters(
                query: 'Food',
                minPricePence: 1000,
                maxPricePence: 2000
            );

            $results = $service->search($filters);

            expect($results)->toHaveCount(1);
            expect($results->first()->name)->toBe('Mid Food');
        });

        it('filters by verified status', function () {
            Product::factory()->verified()->create(['name' => 'Verified Food']);
            Product::factory()->unverified()->create(['name' => 'Unverified Food']);

            $service = new ProductSearchService;
            $filters = new ProductSearchFilters(query: 'Food', verified: true);

            $results = $service->search($filters);

            expect($results)->toHaveCount(1);
            expect($results->first()->is_verified)->toBeTrue();
        });

        it('filters by in_stock status', function () {
            $retailer = Retailer::factory()->create();

            $inStockProduct = Product::factory()->create(['name' => 'In Stock Food']);
            $inStockListing = ProductListing::factory()->create([
                'retailer_id' => $retailer->id,
                'in_stock' => true,
            ]);
            ProductListingMatch::factory()->create([
                'product_id' => $inStockProduct->id,
                'product_listing_id' => $inStockListing->id,
            ]);

            $outOfStockProduct = Product::factory()->create(['name' => 'Out of Stock Food']);
            $outOfStockListing = ProductListing::factory()->create([
                'retailer_id' => $retailer->id,
                'in_stock' => false,
            ]);
            ProductListingMatch::factory()->create([
                'product_id' => $outOfStockProduct->id,
                'product_listing_id' => $outOfStockListing->id,
            ]);

            $service = new ProductSearchService;
            $filters = new ProductSearchFilters(query: 'Food', inStock: true);

            $results = $service->search($filters);

            expect($results)->toHaveCount(1);
            expect($results->first()->name)->toBe('In Stock Food');
        });

        it('paginates results', function () {
            Product::factory()->count(5)->create(['name' => 'Searchable Product']);

            $service = new ProductSearchService;
            $filters = new ProductSearchFilters(query: 'Searchable', perPage: 2, page: 1);

            $results = $service->search($filters);

            expect($results)->toHaveCount(2);
            expect($results->total())->toBe(5);
            expect($results->perPage())->toBe(2);
        });

        it('caches search results', function () {
            Product::factory()->create(['name' => 'Cached Product']);

            $service = new ProductSearchService;
            $filters = new ProductSearchFilters(query: 'Cached');

            // First call should hit the database
            $results1 = $service->search($filters);
            expect($results1)->toHaveCount(1);

            // Delete the product
            Product::query()->delete();

            // Second call should return cached results
            $results2 = $service->search($filters);
            expect($results2)->toHaveCount(1);

            // Clear cache and try again
            Cache::flush();
            $results3 = $service->search($filters);
            expect($results3)->toHaveCount(0);
        });
    });

    describe('suggestions', function () {
        it('returns product suggestions for autocomplete', function () {
            Product::factory()->create(['name' => 'Pedigree Chicken Dog Food', 'brand' => 'Pedigree']);
            Product::factory()->create(['name' => 'Bakers Beef Dog Food', 'brand' => 'Bakers']);

            $service = new ProductSearchService;
            $suggestions = $service->suggestions('Pedigree');

            expect($suggestions)->toHaveCount(1);
            expect($suggestions->first()['name'])->toBe('Pedigree Chicken Dog Food');
            expect($suggestions->first())->toHaveKeys(['id', 'name', 'slug', 'brand', 'relevance']);
        });

        it('returns empty collection for short queries', function () {
            Product::factory()->create(['name' => 'Dog Food']);

            $service = new ProductSearchService;
            $suggestions = $service->suggestions('D');

            expect($suggestions)->toBeEmpty();
        });

        it('limits number of suggestions', function () {
            Product::factory()->count(15)->create(['name' => 'Searchable Product']);

            $service = new ProductSearchService;
            $suggestions = $service->suggestions('Searchable', 5);

            expect($suggestions)->toHaveCount(5);
        });

        it('caches suggestions', function () {
            Product::factory()->create(['name' => 'Cached Suggestion Product']);

            $service = new ProductSearchService;

            // First call
            $suggestions1 = $service->suggestions('Cached');
            expect($suggestions1)->toHaveCount(1);

            // Delete product
            Product::query()->delete();

            // Second call should return cached
            $suggestions2 = $service->suggestions('Cached');
            expect($suggestions2)->toHaveCount(1);
        });
    });

    describe('getFilterOptions', function () {
        it('returns available brands and categories', function () {
            Product::factory()->create(['brand' => 'Pedigree', 'category' => 'Dog Food']);
            Product::factory()->create(['brand' => 'Bakers', 'category' => 'Cat Food']);
            Product::factory()->create(['brand' => 'Pedigree', 'category' => 'Dog Treats']);

            $service = new ProductSearchService;
            $options = $service->getFilterOptions();

            expect($options)->toHaveKeys(['brands', 'categories']);
            expect($options['brands'])->toContain('Pedigree', 'Bakers');
            expect($options['categories'])->toContain('Dog Food', 'Cat Food', 'Dog Treats');
        });

        it('excludes null brands and categories', function () {
            Product::factory()->create(['brand' => 'Pedigree', 'category' => 'Dog Food']);
            Product::factory()->create(['brand' => null, 'category' => null]);

            $service = new ProductSearchService;
            $options = $service->getFilterOptions();

            expect($options['brands'])->toHaveCount(1);
            expect($options['categories'])->toHaveCount(1);
        });

        it('caches filter options', function () {
            Product::factory()->create(['brand' => 'Cached Brand', 'category' => 'Cached Category']);

            $service = new ProductSearchService;

            // First call
            $options1 = $service->getFilterOptions();
            expect($options1['brands'])->toContain('Cached Brand');

            // Delete all products
            Product::query()->delete();

            // Second call should return cached
            $options2 = $service->getFilterOptions();
            expect($options2['brands'])->toContain('Cached Brand');
        });
    });
});

describe('ProductSearchFilters', function () {
    it('creates from array', function () {
        $filters = ProductSearchFilters::fromArray([
            'query' => 'dog food',
            'brand' => 'Pedigree',
            'category' => 'Dog Food',
            'canonical_category' => 'dry_food',
            'min_price' => 100,
            'max_price' => 500,
            'in_stock' => true,
            'verified' => false,
            'per_page' => 20,
            'page' => 2,
        ]);

        expect($filters->query)->toBe('dog food');
        expect($filters->brand)->toBe('Pedigree');
        expect($filters->category)->toBe('Dog Food');
        expect($filters->canonicalCategory)->toBe(CanonicalCategory::DryFood);
        expect($filters->minPricePence)->toBe(100);
        expect($filters->maxPricePence)->toBe(500);
        expect($filters->inStock)->toBeTrue();
        expect($filters->verified)->toBeFalse();
        expect($filters->perPage)->toBe(20);
        expect($filters->page)->toBe(2);
    });

    it('limits per_page to 100', function () {
        $filters = ProductSearchFilters::fromArray(['per_page' => 200]);

        expect($filters->perPage)->toBe(100);
    });

    it('ensures page is at least 1', function () {
        $filters = ProductSearchFilters::fromArray(['page' => 0]);

        expect($filters->page)->toBe(1);
    });

    it('generates unique cache keys', function () {
        $filters1 = new ProductSearchFilters(query: 'dog food');
        $filters2 = new ProductSearchFilters(query: 'cat food');
        $filters3 = new ProductSearchFilters(query: 'dog food');

        expect($filters1->getCacheKey())->not->toBe($filters2->getCacheKey());
        expect($filters1->getCacheKey())->toBe($filters3->getCacheKey());
    });

    it('correctly identifies when query exists', function () {
        $withQuery = new ProductSearchFilters(query: 'test');
        $withEmptyQuery = new ProductSearchFilters(query: '');
        $withNullQuery = new ProductSearchFilters(query: null);
        $withWhitespace = new ProductSearchFilters(query: '   ');

        expect($withQuery->hasQuery())->toBeTrue();
        expect($withEmptyQuery->hasQuery())->toBeFalse();
        expect($withNullQuery->hasQuery())->toBeFalse();
        expect($withWhitespace->hasQuery())->toBeFalse();
    });
});
