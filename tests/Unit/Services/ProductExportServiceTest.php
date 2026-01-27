<?php

use App\Models\Product;
use App\Models\ProductExport;
use App\Models\ProductListing;
use App\Models\Retailer;
use App\Models\User;
use App\Services\ProductExportService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->service = app(ProductExportService::class);
});

describe('exportProductsToCsv', function () {
    it('exports products to CSV file', function () {
        Product::factory()->count(5)->create();

        $export = $this->service->exportProductsToCsv();

        expect($export)->toBeInstanceOf(ProductExport::class);
        expect($export->status)->toBe('completed');
        expect($export->format)->toBe('csv');
        expect($export->type)->toBe('products');
        expect($export->row_count)->toBe(5);
        expect($export->file_path)->not->toBeNull();
        Storage::assertExists($export->file_path);

        $content = Storage::get($export->file_path);
        expect($content)->toContain('ID,Name,Slug,Brand');
    });

    it('applies filters to export', function () {
        Product::factory()->create(['brand' => 'Pedigree', 'lowest_price_pence' => 500]);
        Product::factory()->create(['brand' => 'Bakers', 'lowest_price_pence' => 1500]);
        Product::factory()->create(['brand' => 'Pedigree', 'lowest_price_pence' => 2000]);

        $export = $this->service->exportProductsToCsv([
            'brand' => 'Pedigree',
            'max_price' => 800,
        ]);

        expect($export->row_count)->toBe(1);
    });

    it('handles empty result set', function () {
        $export = $this->service->exportProductsToCsv();

        expect($export->status)->toBe('completed');
        expect($export->row_count)->toBe(0);
    });

    it('records user_id when provided', function () {
        $user = User::factory()->create();
        Product::factory()->create();

        $export = $this->service->exportProductsToCsv([], $user->id);

        expect($export->user_id)->toBe($user->id);
    });
});

describe('exportProductsWithPricesToCsv', function () {
    it('exports products with prices to CSV', function () {
        $retailer = Retailer::factory()->create(['name' => 'Test Retailer']);
        $product = Product::factory()->create(['name' => 'Test Product']);
        $listing = ProductListing::factory()->create([
            'retailer_id' => $retailer->id,
            'price_pence' => 1000,
            'title' => 'Test Listing',
        ]);

        $product->productListings()->attach($listing->id, [
            'confidence_score' => 0.95,
            'match_type' => 'automatic',
            'matched_at' => now(),
        ]);

        $export = $this->service->exportProductsWithPricesToCsv();

        expect($export->status)->toBe('completed');
        expect($export->format)->toBe('csv');
        expect($export->type)->toBe('prices');
        expect($export->row_count)->toBe(1);

        $content = Storage::get($export->file_path);
        expect($content)->toContain('"Product ID","Product Name"');
        expect($content)->toContain('Test Product');
        expect($content)->toContain('Test Retailer');
    });

    it('exports multiple listings per product', function () {
        $product = Product::factory()->create();
        $retailer1 = Retailer::factory()->create();
        $retailer2 = Retailer::factory()->create();

        $listing1 = ProductListing::factory()->create([
            'retailer_id' => $retailer1->id,
            'price_pence' => 1000,
        ]);
        $listing2 = ProductListing::factory()->create([
            'retailer_id' => $retailer2->id,
            'price_pence' => 1200,
        ]);

        $product->productListings()->attach([$listing1->id, $listing2->id], [
            'confidence_score' => 0.95,
            'match_type' => 'automatic',
            'matched_at' => now(),
        ]);

        $export = $this->service->exportProductsWithPricesToCsv();

        expect($export->row_count)->toBe(2);
    });
});

describe('exportProductsToJson', function () {
    it('exports products to JSON file', function () {
        Product::factory()->count(3)->create();

        $export = $this->service->exportProductsToJson();

        expect($export->status)->toBe('completed');
        expect($export->format)->toBe('json');
        expect($export->type)->toBe('products');
        expect($export->row_count)->toBe(3);

        $content = Storage::get($export->file_path);
        $json = json_decode($content, true);

        expect($json)->toHaveKey('products');
        expect($json)->toHaveKey('count');
        expect($json)->toHaveKey('exported_at');
        expect($json['count'])->toBe(3);
        expect($json['products'])->toHaveCount(3);
    });

    it('includes listings in JSON export', function () {
        $product = Product::factory()->create();
        $retailer = Retailer::factory()->create(['name' => 'Test Retailer']);
        $listing = ProductListing::factory()->create([
            'retailer_id' => $retailer->id,
            'title' => 'Test Listing',
        ]);

        $product->productListings()->attach($listing->id, [
            'confidence_score' => 0.95,
            'match_type' => 'automatic',
            'matched_at' => now(),
        ]);

        $export = $this->service->exportProductsToJson();

        $content = Storage::get($export->file_path);
        $json = json_decode($content, true);

        expect($json['products'][0])->toHaveKey('listings');
        expect($json['products'][0]['listings'])->toHaveCount(1);
        expect($json['products'][0]['listings'][0]['title'])->toBe('Test Listing');
        expect($json['products'][0]['listings'][0]['retailer']['name'])->toBe('Test Retailer');
    });
});

describe('cleanupOldExports', function () {
    it('deletes old exports and files', function () {
        Storage::fake();

        $oldExport = ProductExport::factory()->completed()->create([
            'created_at' => now()->subDays(10),
            'file_path' => 'exports/old.csv',
        ]);
        Storage::put($oldExport->file_path, 'old content');

        $recentExport = ProductExport::factory()->completed()->create([
            'created_at' => now()->subDays(3),
            'file_path' => 'exports/recent.csv',
        ]);
        Storage::put($recentExport->file_path, 'recent content');

        $deletedCount = $this->service->cleanupOldExports(7);

        expect($deletedCount)->toBe(1);
        Storage::assertMissing($oldExport->file_path);
        Storage::assertExists($recentExport->file_path);
        expect(ProductExport::find($oldExport->id))->toBeNull();
        expect(ProductExport::find($recentExport->id))->not->toBeNull();
    });

    it('does not delete exports without files', function () {
        $oldExport = ProductExport::factory()->create([
            'created_at' => now()->subDays(10),
            'file_path' => null,
        ]);

        $deletedCount = $this->service->cleanupOldExports(7);

        expect($deletedCount)->toBe(0);
        expect(ProductExport::find($oldExport->id))->not->toBeNull();
    });
});
