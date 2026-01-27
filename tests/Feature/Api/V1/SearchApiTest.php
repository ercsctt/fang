<?php

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\getJson;

beforeEach(function () {
    Cache::flush();
});

describe('GET /api/v1/search', function () {
    it('searches products by name', function () {
        Product::factory()->create(['name' => 'UniqueSearchTermAlpha Dog Food', 'brand' => 'OtherBrand']);
        Product::factory()->create(['name' => 'UniqueSearchTermBeta Beef Dog Food', 'brand' => 'OtherBrand']);

        $response = getJson(route('api.v1.search', ['q' => 'UniqueSearchTermAlpha']));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'UniqueSearchTermAlpha Dog Food');
    });

    it('searches products by brand', function () {
        Product::factory()->create(['name' => 'Generic Food One', 'brand' => 'UniqueBrandAlpha']);
        Product::factory()->create(['name' => 'Other Food Two', 'brand' => 'UniqueBrandBeta']);

        $response = getJson(route('api.v1.search', ['q' => 'UniqueBrandAlpha']));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.brand', 'UniqueBrandAlpha');
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

        $response = getJson(route('api.v1.search', ['q' => 'chicken']));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    });

    it('searches products by category', function () {
        Product::factory()->create(['name' => 'Product A', 'category' => 'Dry Dog Food']);
        Product::factory()->create(['name' => 'Product B', 'category' => 'Wet Cat Food']);

        $response = getJson(route('api.v1.search', ['q' => 'Dry Dog']));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    });

    it('returns validation error for short query', function () {
        $response = getJson(route('api.v1.search', ['q' => 'a']));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    });

    it('returns validation error for missing query', function () {
        $response = getJson(route('api.v1.search'));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    });

    it('filters search results by brand', function () {
        Product::factory()->create(['name' => 'Chicken Food', 'brand' => 'Pedigree']);
        Product::factory()->create(['name' => 'Chicken Food Premium', 'brand' => 'Bakers']);

        $response = getJson(route('api.v1.search', [
            'q' => 'Chicken',
            'brand' => 'Pedigree',
        ]));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.brand', 'Pedigree');
    });

    it('filters search results by category', function () {
        Product::factory()->create(['name' => 'Premium Food A', 'category' => 'Dog Food']);
        Product::factory()->create(['name' => 'Premium Food B', 'category' => 'Cat Food']);

        $response = getJson(route('api.v1.search', [
            'q' => 'Premium',
            'category' => 'Dog Food',
        ]));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category', 'Dog Food');
    });

    it('filters search results by price range', function () {
        Product::factory()->create(['name' => 'Cheap Food', 'lowest_price_pence' => 500]);
        Product::factory()->create(['name' => 'Mid Food', 'lowest_price_pence' => 1500]);
        Product::factory()->create(['name' => 'Expensive Food', 'lowest_price_pence' => 3000]);

        $response = getJson(route('api.v1.search', [
            'q' => 'Food',
            'min_price' => 1000,
            'max_price' => 2000,
        ]));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mid Food');
    });

    it('returns paginated results', function () {
        Product::factory()->count(5)->create(['name' => 'Searchable Product']);

        $response = getJson(route('api.v1.search', [
            'q' => 'Searchable',
            'per_page' => 2,
        ]));

        $response->assertSuccessful()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5);
    });

    it('includes product listings count', function () {
        Product::factory()->create(['name' => 'Test Product']);

        $response = getJson(route('api.v1.search', ['q' => 'Test']));

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'listings_count',
                    ],
                ],
            ]);
    });

    it('filters by verified status', function () {
        Product::factory()->verified()->create(['name' => 'Verified Food']);
        Product::factory()->unverified()->create(['name' => 'Unverified Food']);

        $response = getJson(route('api.v1.search', [
            'q' => 'Food',
            'verified' => 1,
        ]));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_verified', true);
    });

    it('validates max_price must be greater than min_price', function () {
        $response = getJson(route('api.v1.search', [
            'q' => 'Food',
            'min_price' => 2000,
            'max_price' => 1000,
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['max_price']);
    });
});

describe('GET /api/v1/search/suggestions', function () {
    it('returns product suggestions', function () {
        Product::factory()->create(['name' => 'Pedigree Chicken Dog Food', 'brand' => 'Pedigree']);
        Product::factory()->create(['name' => 'Bakers Beef Dog Food', 'brand' => 'Bakers']);

        $response = getJson(route('api.v1.search.suggestions', ['q' => 'Pedigree']));

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'brand',
                        'relevance',
                    ],
                ],
            ])
            ->assertJsonCount(1, 'data');
    });

    it('returns empty array for short queries', function () {
        $response = getJson(route('api.v1.search.suggestions', ['q' => 'a']));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    });

    it('limits suggestions count', function () {
        Product::factory()->count(15)->create(['name' => 'Searchable Product']);

        $response = getJson(route('api.v1.search.suggestions', [
            'q' => 'Searchable',
            'limit' => 5,
        ]));

        $response->assertSuccessful()
            ->assertJsonCount(5, 'data');
    });
});

describe('GET /api/v1/search/filters', function () {
    it('returns available filter options', function () {
        Product::factory()->create(['brand' => 'Pedigree', 'category' => 'Dog Food']);
        Product::factory()->create(['brand' => 'Bakers', 'category' => 'Cat Food']);

        $response = getJson(route('api.v1.search.filters'));

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'brands',
                    'categories',
                ],
            ]);

        $data = $response->json('data');
        expect($data['brands'])->toContain('Pedigree', 'Bakers');
        expect($data['categories'])->toContain('Dog Food', 'Cat Food');
    });

    it('excludes null values from filter options', function () {
        Product::factory()->create(['brand' => 'ValidBrand', 'category' => 'ValidCategory']);
        Product::factory()->create(['brand' => null, 'category' => null]);

        $response = getJson(route('api.v1.search.filters'));

        $response->assertSuccessful();

        $data = $response->json('data');
        expect($data['brands'])->toHaveCount(1);
        expect($data['categories'])->toHaveCount(1);
    });
});
