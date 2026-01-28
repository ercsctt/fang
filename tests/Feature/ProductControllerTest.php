<?php

declare(strict_types=1);

use App\Enums\MatchType;
use App\Models\Product;
use App\Models\ProductListing;
use App\Models\Retailer;

beforeEach(function () {
    $this->withoutVite();
    $this->retailer = Retailer::factory()->create(['is_active' => true]);
});

function attachProductToListing(ProductListing $listing, Product $product): void
{
    $listing->products()->attach($product->id, [
        'confidence_score' => 95.0,
        'match_type' => MatchType::Barcode->value,
        'matched_at' => now(),
    ]);
}

test('home page loads with featured products and stats', function () {
    $product = Product::factory()->create(['primary_image' => 'https://example.com/image.jpg']);
    $listing = ProductListing::factory()
        ->inStock()
        ->create(['retailer_id' => $this->retailer->id]);
    attachProductToListing($listing, $product);

    $response = $this->get('/');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Home')
            ->has('featuredProducts')
            ->has('priceDrops')
            ->has('retailers')
            ->has('stats')
        );
});

test('products index page loads with paginated products', function () {
    $products = Product::factory()->count(3)->create();

    foreach ($products as $product) {
        $listing = ProductListing::factory()->create(['retailer_id' => $this->retailer->id]);
        attachProductToListing($listing, $product);
    }

    $response = $this->get('/products');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Index')
            ->has('products.data', 3)
            ->has('filters')
            ->has('brands')
            ->has('categories')
            ->has('retailers')
        );
});

test('products can be filtered by search', function () {
    $targetProduct = Product::factory()->create(['name' => 'Special Dog Food']);
    $otherProduct = Product::factory()->create(['name' => 'Cat Treats']);

    $listing1 = ProductListing::factory()->create(['retailer_id' => $this->retailer->id]);
    attachProductToListing($listing1, $targetProduct);

    $listing2 = ProductListing::factory()->create(['retailer_id' => $this->retailer->id]);
    attachProductToListing($listing2, $otherProduct);

    $response = $this->get('/products?search=Special');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Index')
            ->has('products.data', 1)
            ->where('filters.search', 'Special')
        );
});

test('products can be filtered by brand', function () {
    $pedigreeProduct = Product::factory()->create(['brand' => 'Pedigree']);
    $bakersProduct = Product::factory()->create(['brand' => 'Bakers']);

    $listing1 = ProductListing::factory()->create(['retailer_id' => $this->retailer->id]);
    attachProductToListing($listing1, $pedigreeProduct);

    $listing2 = ProductListing::factory()->create(['retailer_id' => $this->retailer->id]);
    attachProductToListing($listing2, $bakersProduct);

    $response = $this->get('/products?brand=Pedigree');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Index')
            ->has('products.data', 1)
            ->where('filters.brand', 'Pedigree')
        );
});

test('products can be filtered by category', function () {
    $dryFoodProduct = Product::factory()->create(['category' => 'Dry Food']);
    $wetFoodProduct = Product::factory()->create(['category' => 'Wet Food']);

    $listing1 = ProductListing::factory()->create(['retailer_id' => $this->retailer->id]);
    attachProductToListing($listing1, $dryFoodProduct);

    $listing2 = ProductListing::factory()->create(['retailer_id' => $this->retailer->id]);
    attachProductToListing($listing2, $wetFoodProduct);

    $response = $this->get('/products?category=Dry+Food');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Index')
            ->has('products.data', 1)
            ->where('filters.category', 'Dry Food')
        );
});

test('products can be filtered by retailer', function () {
    $tescoRetailer = Retailer::factory()->create(['name' => 'Tesco', 'is_active' => true]);
    $asdaRetailer = Retailer::factory()->create(['name' => 'Asda', 'is_active' => true]);

    $tescoProduct = Product::factory()->create();
    $asdaProduct = Product::factory()->create();

    $tescoListing = ProductListing::factory()->create(['retailer_id' => $tescoRetailer->id]);
    attachProductToListing($tescoListing, $tescoProduct);

    $asdaListing = ProductListing::factory()->create(['retailer_id' => $asdaRetailer->id]);
    attachProductToListing($asdaListing, $asdaProduct);

    $response = $this->get("/products?retailer={$tescoRetailer->id}");

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Index')
            ->has('products.data', 1)
            ->where('filters.retailer', (string) $tescoRetailer->id)
        );
});

test('products can be filtered by price range', function () {
    $cheapProduct = Product::factory()->create(['lowest_price_pence' => 500]);
    $expensiveProduct = Product::factory()->create(['lowest_price_pence' => 5000]);

    $listing1 = ProductListing::factory()->create(['retailer_id' => $this->retailer->id]);
    attachProductToListing($listing1, $cheapProduct);

    $listing2 = ProductListing::factory()->create(['retailer_id' => $this->retailer->id]);
    attachProductToListing($listing2, $expensiveProduct);

    $response = $this->get('/products?min_price=1&max_price=10');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Index')
            ->has('products.data', 1)
        );
});

test('products can be sorted by price', function () {
    $cheapProduct = Product::factory()->create(['lowest_price_pence' => 500, 'name' => 'Cheap']);
    $expensiveProduct = Product::factory()->create(['lowest_price_pence' => 5000, 'name' => 'Expensive']);

    $listing1 = ProductListing::factory()->create(['retailer_id' => $this->retailer->id]);
    attachProductToListing($listing1, $cheapProduct);

    $listing2 = ProductListing::factory()->create(['retailer_id' => $this->retailer->id]);
    attachProductToListing($listing2, $expensiveProduct);

    $response = $this->get('/products?sort=price&dir=asc');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Index')
            ->where('products.data.0.name', 'Cheap')
            ->where('filters.sort', 'price')
            ->where('filters.dir', 'asc')
        );
});

test('product show page loads with product details', function () {
    $product = Product::factory()->create();
    $listing = ProductListing::factory()
        ->inStock()
        ->create(['retailer_id' => $this->retailer->id]);
    attachProductToListing($listing, $product);

    $response = $this->get("/products/{$product->slug}");

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Products/Show', false)
            ->has('product')
            ->has('priceHistory')
            ->has('reviews')
            ->has('averageRating')
            ->has('totalReviewCount')
        );
});

test('search endpoint returns matching products as json', function () {
    $product = Product::factory()->create(['name' => 'Pedigree Chicken Food']);
    $listing = ProductListing::factory()->create(['retailer_id' => $this->retailer->id]);
    attachProductToListing($listing, $product);

    $response = $this->getJson('/products/search?q=Pedigree');

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['name' => 'Pedigree Chicken Food']);
});

test('search endpoint requires minimum query length', function () {
    $response = $this->getJson('/products/search?q=a');

    $response->assertOk()
        ->assertJsonCount(0);
});

test('retailers filter includes only active retailers', function () {
    $activeRetailer = Retailer::factory()->create(['is_active' => true, 'name' => 'Active Store']);
    Retailer::factory()->create(['is_active' => false, 'name' => 'Inactive Store']);

    $product = Product::factory()->create();
    $listing = ProductListing::factory()->create(['retailer_id' => $activeRetailer->id]);
    attachProductToListing($listing, $product);

    $response = $this->get('/products');

    $response->assertOk();

    $inertiaProps = $response->original->getData()['page']['props'];
    $retailerNames = collect($inertiaProps['retailers'])->pluck('name')->toArray();

    expect($retailerNames)->toContain('Active Store');
    expect($retailerNames)->not->toContain('Inactive Store');
});
