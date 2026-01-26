<?php

declare(strict_types=1);

use App\Enums\MatchType;
use App\Models\Product;
use App\Models\ProductListing;
use App\Models\ProductListingMatch;
use App\Models\Retailer;
use App\Services\ProductMatcher;
use App\Services\ProductNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupMatcher(): array
{
    $normalizer = new ProductNormalizer;
    $matcher = new ProductMatcher($normalizer);
    $retailer = Retailer::factory()->create();

    return compact('normalizer', 'matcher', 'retailer');
}

// Exact matching tests
test('exact matching finds exact match with same brand, name, and weight', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    $product = Product::factory()->create([
        'name' => 'Pedigree Chicken Complete Dry Dog Food',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
        'quantity' => 1,
    ]);

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Pedigree Chicken Complete Dry Dog Food',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
        'quantity' => 1,
    ]);

    $result = $matcher->findExactMatch($listing);

    expect($result)->not->toBeNull()
        ->and($result['product']->id)->toBe($product->id)
        ->and($result['confidence'])->toBeGreaterThanOrEqual(95.0);
});

test('exact matching returns null when brand does not match', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    Product::factory()->create([
        'name' => 'Pedigree Chicken Dog Food',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
    ]);

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Royal Canin Chicken Dog Food',
        'brand' => 'Royal Canin',
        'weight_grams' => 2000,
    ]);

    $result = $matcher->findExactMatch($listing);

    expect($result)->toBeNull();
});

test('exact matching returns null when listing has no brand', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    Product::factory()->create([
        'name' => 'Pedigree Chicken Dog Food',
        'brand' => 'Pedigree',
    ]);

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Pedigree Chicken Dog Food',
        'brand' => null,
    ]);

    $result = $matcher->findExactMatch($listing);

    expect($result)->toBeNull();
});

test('exact matching matches products with minor variations as exact match', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    // Ensure database is clean
    expect(Product::count())->toBe(0, 'Database should be clean');

    $product = Product::factory()->create([
        'name' => 'Pedigree Chicken Dog Food 2kg',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
        'quantity' => 1,
    ]);

    expect(Product::count())->toBe(1, 'Should have exactly one product');

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Pedigree Chicken Dog Food 2 kg',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
        'quantity' => 1,
    ]);

    $result = $matcher->findExactMatch($listing);

    expect($result)->not->toBeNull()
        ->and($result['product']->id)->toBe($product->id);
});

test('exact matching falls back to fuzzy match for slight title variations', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    $product = Product::factory()->create([
        'name' => 'Pedigree Chicken Dog Food',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
        'quantity' => null,
    ]);

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Pedigree Chicken Dry Dog Food',
        'brand' => 'Pedigree',
        'weight_grams' => 5000,
        'quantity' => null,
    ]);

    // Should not be exact match (similarity ~92%, weight differs)
    $exactResult = $matcher->findExactMatch($listing);
    expect($exactResult)->toBeNull();

    // But should be fuzzy match
    $fuzzyResult = $matcher->findFuzzyMatch($listing);
    expect($fuzzyResult)->not->toBeNull()
        ->and($fuzzyResult['product']->id)->toBe($product->id);
});

// Fuzzy matching tests
test('fuzzy matching finds fuzzy match with similar names', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    $product = Product::factory()->create([
        'name' => 'Harringtons Salmon Dog Food',
        'brand' => null,
        'weight_grams' => 2000,
        'quantity' => 1,
    ]);

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Harringtons Salmon Dogfood 2kg',
        'brand' => null,
        'weight_grams' => 2000,
        'quantity' => 1,
    ]);

    $result = $matcher->findFuzzyMatch($listing);

    expect($result)->not->toBeNull()
        ->and($result['product']->id)->toBe($product->id)
        ->and($result['confidence'])->toBeGreaterThanOrEqual(70.0)
        ->and($result['confidence'])->toBeLessThan(95.0);
});

test('fuzzy matching returns null for very different titles', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    Product::factory()->create([
        'name' => 'Pedigree Chicken Dog Food',
        'brand' => 'Pedigree',
    ]);

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Royal Canin Cat Treats',
        'brand' => 'Royal Canin',
    ]);

    $result = $matcher->findFuzzyMatch($listing);

    expect($result)->toBeNull();
});

test('fuzzy matching returns null for listing with empty string title', function () {
    ['matcher' => $matcher, 'normalizer' => $normalizer, 'retailer' => $retailer] = setupMatcher();

    Product::factory()->create([
        'name' => 'Pedigree Chicken Dog Food',
        'brand' => 'Pedigree',
    ]);

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Placeholder Title',
    ]);

    $listing->title = '';
    $listing->save();

    $result = $matcher->findFuzzyMatch($listing);

    expect($result)->toBeNull();
});

// Match method tests
test('match method returns existing match if already matched', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    $product = Product::factory()->create();
    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
    ]);

    $existingMatch = ProductListingMatch::factory()->create([
        'product_id' => $product->id,
        'product_listing_id' => $listing->id,
        'confidence_score' => 95.0,
        'match_type' => MatchType::Exact,
    ]);

    $result = $matcher->match($listing);

    expect($result->id)->toBe($existingMatch->id)
        ->and($result->product_id)->toBe($product->id);
});

test('match method creates exact match when found', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    // Ensure database is clean
    expect(Product::count())->toBe(0);

    $product = Product::factory()->create([
        'name' => 'Pedigree Chicken Dog Food',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
        'quantity' => null,
    ]);

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Pedigree Chicken Dog Food',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
        'quantity' => null,
    ]);

    $result = $matcher->match($listing);

    expect($result)->toBeInstanceOf(ProductListingMatch::class)
        ->and($result->product_id)->toBe($product->id)
        ->and($result->match_type)->toBe(MatchType::Exact)
        ->and($result->confidence_score)->toBeGreaterThanOrEqual(95.0);
});

test('match method creates fuzzy match when no exact match found', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    $product = Product::factory()->create([
        'name' => 'Pedigree Chicken Dog Food',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
        'quantity' => 1,
    ]);

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Pedigree Chicken Dry Dog Food',
        'brand' => 'Pedigree',
        'weight_grams' => 5000,
        'quantity' => 1,
    ]);

    // Verify no exact match (weight differs, title similarity ~92%)
    expect($matcher->findExactMatch($listing))->toBeNull();

    $result = $matcher->match($listing);

    expect($result->match_type)->toBe(MatchType::Fuzzy)
        ->and($result->product_id)->toBe($product->id)
        ->and($result->confidence_score)->toBeGreaterThanOrEqual(70.0)
        ->and($result->confidence_score)->toBeLessThan(95.0);
});

test('match method creates new product when no match found and createProductIfNoMatch is true', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Unique Brand Special Formula Dog Food',
        'brand' => 'Unique Brand',
        'description' => 'A special dog food formula',
        'category' => 'Dog Food',
        'weight_grams' => 5000,
        'price_pence' => 1999,
    ]);

    $productCountBefore = Product::count();

    $result = $matcher->match($listing, createProductIfNoMatch: true);

    expect(Product::count())->toBe($productCountBefore + 1)
        ->and($result->match_type)->toBe(MatchType::Exact)
        ->and($result->confidence_score)->toBe(100.0);

    $newProduct = $result->product;
    expect($newProduct->name)->toBe($listing->title)
        ->and($newProduct->brand)->toBe('Unique Brand')
        ->and($newProduct->weight_grams)->toBe(5000)
        ->and($newProduct->is_verified)->toBeFalse();
});

test('match method returns null when no match found and createProductIfNoMatch is false', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Unique Brand Special Formula',
        'brand' => 'Unique Brand',
    ]);

    $result = $matcher->match($listing, createProductIfNoMatch: false);

    expect($result)->toBeNull();
});

// Confidence calculation tests
test('calculateConfidence gives full score for perfect match', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    $product = Product::factory()->create([
        'name' => 'Pedigree Chicken Dog Food',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
        'quantity' => 1,
    ]);

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Pedigree Chicken Dog Food',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
        'quantity' => 1,
    ]);

    $confidence = $matcher->calculateConfidence($listing, $product, 100.0);

    expect($confidence)->toBe(100.0);
});

test('calculateConfidence gives partial score for brand mismatch', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    $product = Product::factory()->create([
        'name' => 'Chicken Dog Food',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
    ]);

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Chicken Dog Food',
        'brand' => 'Royal Canin',
        'weight_grams' => 2000,
    ]);

    $confidence = $matcher->calculateConfidence($listing, $product, 100.0);

    expect($confidence)->toBeLessThan(100.0);
});

test('calculateConfidence gives partial score for weight mismatch', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    $product = Product::factory()->create([
        'name' => 'Pedigree Chicken Dog Food',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
    ]);

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Pedigree Chicken Dog Food',
        'brand' => 'Pedigree',
        'weight_grams' => 5000,
    ]);

    $confidence = $matcher->calculateConfidence($listing, $product, 100.0);

    expect($confidence)->toBeLessThan(95.0);
});

// Price statistics tests
test('product price statistics update updates product lowest price after match', function () {
    ['matcher' => $matcher, 'retailer' => $retailer] = setupMatcher();

    $product = Product::factory()->create([
        'name' => 'Pedigree Chicken Dog Food',
        'brand' => 'Pedigree',
        'lowest_price_pence' => 2000,
        'average_price_pence' => 2000,
        'weight_grams' => 2000,
        'quantity' => 1,
    ]);

    $listing = ProductListing::factory()->create([
        'retailer_id' => $retailer->id,
        'title' => 'Pedigree Chicken Dog Food',
        'brand' => 'Pedigree',
        'price_pence' => 1500,
        'in_stock' => true,
        'weight_grams' => 2000,
        'quantity' => 1,
    ]);

    $matcher->match($listing);

    $product->refresh();

    expect($product->lowest_price_pence)->toBe(1500);
});

// Multi-retailer tests
test('matching across retailers matches same product from different retailers', function () {
    ['matcher' => $matcher] = setupMatcher();

    $product = Product::factory()->create([
        'name' => 'Pedigree Chicken Complete',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
        'quantity' => 1,
    ]);

    $retailer1 = Retailer::factory()->create(['name' => 'B&M']);
    $retailer2 = Retailer::factory()->create(['name' => 'Pets at Home']);

    $listing1 = ProductListing::factory()->create([
        'retailer_id' => $retailer1->id,
        'title' => 'Pedigree Chicken Complete',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
        'quantity' => 1,
    ]);

    $listing2 = ProductListing::factory()->create([
        'retailer_id' => $retailer2->id,
        'title' => 'Pedigree Chicken Complete Dry Dog Food',
        'brand' => 'Pedigree',
        'weight_grams' => 2000,
        'quantity' => 1,
    ]);

    $match1 = $matcher->match($listing1);
    $match2 = $matcher->match($listing2);

    expect($match1->product_id)->toBe($product->id)
        ->and($match2->product_id)->toBe($product->id);
});
