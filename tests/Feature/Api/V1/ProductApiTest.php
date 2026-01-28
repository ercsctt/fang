<?php

use App\Models\Product;
use App\Models\ProductListing;
use App\Models\ProductListingMatch;
use App\Models\ProductListingPrice;
use App\Models\Retailer;

use function Pest\Laravel\getJson;

describe('GET /api/v1/products', function () {
    it('returns a paginated list of products', function () {
        Product::factory()->count(3)->create();

        $response = getJson(route('api.v1.products.index'));

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'brand',
                        'category',
                        'lowest_price_pence',
                        'lowest_price_formatted',
                    ],
                ],
                'links',
                'meta',
            ])
            ->assertJsonCount(3, 'data');
    });

    it('filters products by brand', function () {
        Product::factory()->create(['brand' => 'Pedigree']);
        Product::factory()->create(['brand' => 'Bakers']);

        $response = getJson(route('api.v1.products.index', ['brand' => 'Pedigree']));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.brand', 'Pedigree');
    });

    it('filters products by category', function () {
        Product::factory()->create(['category' => 'Dog Food']);
        Product::factory()->create(['category' => 'Cat Food']);

        $response = getJson(route('api.v1.products.index', ['category' => 'Dog Food']));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category', 'Dog Food');
    });

    it('filters products by price range', function () {
        Product::factory()->create(['lowest_price_pence' => 500]);
        Product::factory()->create(['lowest_price_pence' => 1500]);
        Product::factory()->create(['lowest_price_pence' => 2500]);

        $response = getJson(route('api.v1.products.index', [
            'min_price' => 1000,
            'max_price' => 2000,
        ]));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lowest_price_pence', 1500);
    });

    it('filters products by verified status', function () {
        Product::factory()->verified()->create();
        Product::factory()->unverified()->create();

        $response = getJson(route('api.v1.products.index', ['verified' => 'true']));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_verified', true);
    });

    it('sorts products by name', function () {
        Product::factory()->create(['name' => 'Zebra Food']);
        Product::factory()->create(['name' => 'Alpha Food']);

        $response = getJson(route('api.v1.products.index', [
            'sort' => 'name',
            'direction' => 'asc',
        ]));

        $response->assertSuccessful()
            ->assertJsonPath('data.0.name', 'Alpha Food')
            ->assertJsonPath('data.1.name', 'Zebra Food');
    });

    it('respects per_page parameter with max limit', function () {
        Product::factory()->count(5)->create();

        $response = getJson(route('api.v1.products.index', ['per_page' => 2]));

        $response->assertSuccessful()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2);
    });

    it('limits per_page to 100', function () {
        Product::factory()->count(5)->create();

        $response = getJson(route('api.v1.products.index', ['per_page' => 200]));

        $response->assertSuccessful()
            ->assertJsonPath('meta.per_page', 100);
    });
});

describe('GET /api/v1/products/{slug}', function () {
    it('returns a single product by slug', function () {
        $product = Product::factory()->create(['slug' => 'test-product']);

        $response = getJson(route('api.v1.products.show', $product->slug));

        $response->assertSuccessful()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.slug', 'test-product');
    });

    it('returns product with retailer listings', function () {
        $retailer = Retailer::factory()->create();
        $product = Product::factory()->create();
        $listing = ProductListing::factory()->create(['retailer_id' => $retailer->id]);
        ProductListingMatch::factory()->create([
            'product_id' => $product->id,
            'product_listing_id' => $listing->id,
        ]);

        $response = getJson(route('api.v1.products.show', $product->slug));

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'listings' => [
                        '*' => [
                            'id',
                            'title',
                            'price_pence',
                            'retailer' => [
                                'id',
                                'name',
                            ],
                        ],
                    ],
                ],
            ]);
    });

    it('returns 404 for non-existent product', function () {
        $response = getJson(route('api.v1.products.show', 'non-existent-slug'));

        $response->assertNotFound();
    });
});

describe('GET /api/v1/products/{slug}/price-history', function () {
    it('returns price history for a product', function () {
        $retailer = Retailer::factory()->create();
        $product = Product::factory()->create();
        $listing = ProductListing::factory()->create(['retailer_id' => $retailer->id]);
        ProductListingMatch::factory()->create([
            'product_id' => $product->id,
            'product_listing_id' => $listing->id,
        ]);

        ProductListingPrice::factory()->create([
            'product_listing_id' => $listing->id,
            'price_pence' => 1000,
            'recorded_at' => now()->subDays(2),
        ]);
        ProductListingPrice::factory()->create([
            'product_listing_id' => $listing->id,
            'price_pence' => 900,
            'recorded_at' => now()->subDay(),
        ]);

        $response = getJson(route('api.v1.products.price-history', $product->slug));

        $response->assertSuccessful()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'price_pence',
                        'price_formatted',
                        'recorded_at',
                    ],
                ],
            ]);
    });

    it('filters price history by date range', function () {
        $retailer = Retailer::factory()->create();
        $product = Product::factory()->create();
        $listing = ProductListing::factory()->create(['retailer_id' => $retailer->id]);
        ProductListingMatch::factory()->create([
            'product_id' => $product->id,
            'product_listing_id' => $listing->id,
        ]);

        ProductListingPrice::factory()->create([
            'product_listing_id' => $listing->id,
            'recorded_at' => now()->subDays(10),
        ]);
        ProductListingPrice::factory()->create([
            'product_listing_id' => $listing->id,
            'recorded_at' => now()->subDays(3),
        ]);

        $response = getJson(route('api.v1.products.price-history', [
            'slug' => $product->slug,
            'from' => now()->subDays(5)->toDateString(),
        ]));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data');
    });

    it('returns 404 for non-existent product', function () {
        $response = getJson(route('api.v1.products.price-history', 'non-existent-slug'));

        $response->assertNotFound();
    });
});
