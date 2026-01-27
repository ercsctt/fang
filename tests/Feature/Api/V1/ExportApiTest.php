<?php

use App\Models\Product;
use App\Models\ProductExport;
use App\Models\ProductListing;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Storage::fake();
});

describe('POST /api/v1/exports', function () {
    it('creates a CSV export of products', function () {
        $user = User::factory()->create();
        Product::factory()->count(5)->create();

        $response = actingAs($user)->postJson(route('api.v1.exports.store'), [
            'type' => 'products',
            'format' => 'csv',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'message',
                'export' => [
                    'id',
                    'type',
                    'format',
                    'status',
                    'row_count',
                    'file_path',
                    'file_name',
                ],
            ]);

        assertDatabaseHas('product_exports', [
            'user_id' => $user->id,
            'type' => 'products',
            'format' => 'csv',
            'status' => 'completed',
        ]);
    });

    it('creates a JSON export of products', function () {
        $user = User::factory()->create();
        Product::factory()->count(3)->create();

        $response = actingAs($user)->postJson(route('api.v1.exports.store'), [
            'type' => 'products',
            'format' => 'json',
        ]);

        $response->assertCreated()
            ->assertJsonPath('export.type', 'products')
            ->assertJsonPath('export.format', 'json')
            ->assertJsonPath('export.status', 'completed');
    });

    it('creates a CSV export of product prices across retailers', function () {
        $user = User::factory()->create();
        $retailer = Retailer::factory()->create();
        $product = Product::factory()->create();

        $listing = ProductListing::factory()->create([
            'retailer_id' => $retailer->id,
            'price_pence' => 1000,
        ]);

        $product->productListings()->attach($listing->id, [
            'confidence_score' => 0.95,
            'match_type' => 'automatic',
            'matched_at' => now(),
        ]);

        $response = actingAs($user)->postJson(route('api.v1.exports.store'), [
            'type' => 'prices',
            'format' => 'csv',
        ]);

        $response->assertCreated();

        assertDatabaseHas('product_exports', [
            'user_id' => $user->id,
            'type' => 'prices',
            'format' => 'csv',
            'status' => 'completed',
        ]);
    });

    it('applies filters to product export', function () {
        $user = User::factory()->create();
        Product::factory()->create(['brand' => 'Pedigree', 'lowest_price_pence' => 500]);
        Product::factory()->create(['brand' => 'Bakers', 'lowest_price_pence' => 1500]);

        $response = actingAs($user)->postJson(route('api.v1.exports.store'), [
            'type' => 'products',
            'format' => 'csv',
            'filters' => [
                'brand' => 'Pedigree',
                'min_price' => 400,
                'max_price' => 800,
            ],
        ]);

        $response->assertCreated();

        $export = ProductExport::find($response->json('export.id'));
        expect($export->row_count)->toBe(1);
        expect($export->filters)->toBe([
            'brand' => 'Pedigree',
            'min_price' => 400,
            'max_price' => 800,
        ]);
    });

    it('validates export request', function () {
        $user = User::factory()->create();

        $response = actingAs($user)->postJson(route('api.v1.exports.store'), [
            'type' => 'invalid',
            'format' => 'pdf',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'format']);
    });

    it('requires authentication', function () {
        $response = postJson(route('api.v1.exports.store'), [
            'type' => 'products',
            'format' => 'csv',
        ]);

        $response->assertUnauthorized();
    });
});

describe('GET /api/v1/exports', function () {
    it('returns list of exports for authenticated user', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        ProductExport::factory()->count(3)->create(['user_id' => $user->id]);
        ProductExport::factory()->count(2)->create(['user_id' => $otherUser->id]);

        $response = actingAs($user)->getJson(route('api.v1.exports.index'));

        $response->assertSuccessful()
            ->assertJsonCount(3, 'data');
    });

    it('requires authentication', function () {
        $response = getJson(route('api.v1.exports.index'));

        $response->assertUnauthorized();
    });
});

describe('GET /api/v1/exports/{export}', function () {
    it('returns export details', function () {
        $user = User::factory()->create();
        $export = ProductExport::factory()->create(['user_id' => $user->id]);

        $response = actingAs($user)->getJson(route('api.v1.exports.show', $export));

        $response->assertSuccessful()
            ->assertJsonPath('id', $export->id)
            ->assertJsonPath('type', $export->type);
    });

    it('prevents accessing another user export', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $export = ProductExport::factory()->create(['user_id' => $otherUser->id]);

        $response = actingAs($user)->getJson(route('api.v1.exports.show', $export));

        $response->assertForbidden();
    });

    it('requires authentication', function () {
        $export = ProductExport::factory()->create();

        $response = getJson(route('api.v1.exports.show', $export));

        $response->assertUnauthorized();
    });
});

describe('GET /api/v1/exports/{export}/download', function () {
    it('downloads completed export file', function () {
        Storage::fake();

        $user = User::factory()->create();
        $export = ProductExport::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'file_path' => 'exports/test.csv',
            'file_name' => 'test.csv',
        ]);

        Storage::put($export->file_path, 'test content');

        $response = actingAs($user)->getJson(route('api.v1.exports.download', $export));

        $response->assertSuccessful();
    });

    it('returns error when export is not completed', function () {
        $user = User::factory()->create();
        $export = ProductExport::factory()->create([
            'user_id' => $user->id,
            'status' => 'processing',
        ]);

        $response = actingAs($user)->getJson(route('api.v1.exports.download', $export));

        $response->assertBadRequest()
            ->assertJsonPath('message', 'Export is not ready for download');
    });

    it('returns error when file does not exist', function () {
        $user = User::factory()->create();
        $export = ProductExport::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'file_path' => 'exports/nonexistent.csv',
        ]);

        $response = actingAs($user)->getJson(route('api.v1.exports.download', $export));

        $response->assertNotFound();
    });

    it('prevents downloading another user export', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $export = ProductExport::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'completed',
        ]);

        $response = actingAs($user)->getJson(route('api.v1.exports.download', $export));

        $response->assertForbidden();
    });

    it('requires authentication', function () {
        $export = ProductExport::factory()->create();

        $response = getJson(route('api.v1.exports.download', $export));

        $response->assertUnauthorized();
    });
});

describe('DELETE /api/v1/exports/{export}', function () {
    it('deletes export and file', function () {
        Storage::fake();

        $user = User::factory()->create();
        $export = ProductExport::factory()->create([
            'user_id' => $user->id,
            'file_path' => 'exports/test.csv',
        ]);

        Storage::put($export->file_path, 'test content');

        $response = actingAs($user)->deleteJson(route('api.v1.exports.destroy', $export));

        $response->assertSuccessful();
        Storage::assertMissing($export->file_path);
        expect(ProductExport::find($export->id))->toBeNull();
    });

    it('prevents deleting another user export', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $export = ProductExport::factory()->create(['user_id' => $otherUser->id]);

        $response = actingAs($user)->deleteJson(route('api.v1.exports.destroy', $export));

        $response->assertForbidden();
        expect(ProductExport::find($export->id))->not->toBeNull();
    });

    it('requires authentication', function () {
        $export = ProductExport::factory()->create();

        $response = deleteJson(route('api.v1.exports.destroy', $export));

        $response->assertUnauthorized();
    });
});
