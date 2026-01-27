<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductExport;
use App\Models\ProductListing;
use Illuminate\Support\Facades\Storage;

class ProductExportService
{
    /**
     * Export products to CSV format.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportProductsToCsv(array $filters = [], ?int $userId = null): ProductExport
    {
        $export = $this->createExportRecord('products', 'csv', $filters, $userId);

        try {
            $export->update(['status' => 'processing', 'started_at' => now()]);

            $fileName = $this->generateFileName('products', 'csv');
            $filePath = "exports/{$fileName}";

            $handle = fopen('php://temp', 'w+');
            if ($handle === false) {
                throw new \RuntimeException('Failed to create temporary file');
            }

            // Write CSV header
            fputcsv($handle, [
                'ID',
                'Name',
                'Slug',
                'Brand',
                'Category',
                'Canonical Category',
                'Subcategory',
                'Weight (g)',
                'Quantity',
                'Lowest Price (£)',
                'Average Price (£)',
                'Verified',
                'Listing Count',
                'Created At',
                'Updated At',
            ]);

            $rowCount = 0;

            // Use cursor for memory-efficient iteration
            $this->buildProductQuery($filters)
                ->withCount('productListings')
                ->cursor()
                ->each(function (Product $product) use ($handle, &$rowCount) {
                    fputcsv($handle, [
                        $product->id,
                        $product->name,
                        $product->slug,
                        $product->brand,
                        $product->category,
                        $product->canonical_category?->value,
                        $product->subcategory,
                        $product->weight_grams,
                        $product->quantity,
                        $product->lowest_price_pence ? number_format($product->lowest_price_pence / 100, 2) : null,
                        $product->average_price_pence ? number_format($product->average_price_pence / 100, 2) : null,
                        $product->is_verified ? 'Yes' : 'No',
                        $product->product_listings_count ?? 0,
                        $product->created_at?->toIso8601String(),
                        $product->updated_at?->toIso8601String(),
                    ]);
                    $rowCount++;
                });

            rewind($handle);
            $contents = stream_get_contents($handle);
            if ($contents === false) {
                throw new \RuntimeException('Failed to read temporary file contents');
            }
            fclose($handle);

            Storage::put($filePath, $contents);

            $export->update([
                'status' => 'completed',
                'file_path' => $filePath,
                'file_name' => $fileName,
                'file_size_bytes' => Storage::size($filePath),
                'row_count' => $rowCount,
                'completed_at' => now(),
            ]);

            return $export->fresh();
        } catch (\Exception $e) {
            $export->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Export products with prices to CSV format.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportProductsWithPricesToCsv(array $filters = [], ?int $userId = null): ProductExport
    {
        $export = $this->createExportRecord('prices', 'csv', $filters, $userId);

        try {
            $export->update(['status' => 'processing', 'started_at' => now()]);

            $fileName = $this->generateFileName('product-prices', 'csv');
            $filePath = "exports/{$fileName}";

            $handle = fopen('php://temp', 'w+');
            if ($handle === false) {
                throw new \RuntimeException('Failed to create temporary file');
            }

            // Write CSV header
            fputcsv($handle, [
                'Product ID',
                'Product Name',
                'Product Brand',
                'Retailer',
                'Listing Title',
                'Current Price (£)',
                'Original Price (£)',
                'On Sale',
                'In Stock',
                'URL',
                'Last Scraped',
            ]);

            $rowCount = 0;

            // Use cursor for memory-efficient iteration
            $this->buildProductQuery($filters)
                ->with(['productListings' => function ($query) {
                    $query->with('retailer')
                        ->orderBy('price_pence');
                }])
                ->cursor()
                ->each(function (Product $product) use ($handle, &$rowCount) {
                    foreach ($product->productListings as $listing) {
                        fputcsv($handle, [
                            $product->id,
                            $product->name,
                            $product->brand,
                            $listing->retailer?->name,
                            $listing->title,
                            $listing->price_pence ? number_format($listing->price_pence / 100, 2) : null,
                            $listing->original_price_pence ? number_format($listing->original_price_pence / 100, 2) : null,
                            $listing->isOnSale() ? 'Yes' : 'No',
                            $listing->in_stock ? 'Yes' : 'No',
                            $listing->url,
                            $listing->last_scraped_at?->toIso8601String(),
                        ]);
                        $rowCount++;
                    }
                });

            rewind($handle);
            $contents = stream_get_contents($handle);
            if ($contents === false) {
                throw new \RuntimeException('Failed to read temporary file contents');
            }
            fclose($handle);

            Storage::put($filePath, $contents);

            $export->update([
                'status' => 'completed',
                'file_path' => $filePath,
                'file_name' => $fileName,
                'file_size_bytes' => Storage::size($filePath),
                'row_count' => $rowCount,
                'completed_at' => now(),
            ]);

            return $export->fresh();
        } catch (\Exception $e) {
            $export->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Export products to JSON format.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportProductsToJson(array $filters = [], ?int $userId = null): ProductExport
    {
        $export = $this->createExportRecord('products', 'json', $filters, $userId);

        try {
            $export->update(['status' => 'processing', 'started_at' => now()]);

            $fileName = $this->generateFileName('products', 'json');
            $filePath = "exports/{$fileName}";

            $products = [];
            $rowCount = 0;

            // Use cursor for memory-efficient iteration
            $this->buildProductQuery($filters)
                ->with(['productListings.retailer'])
                ->cursor()
                ->each(function (Product $product) use (&$products, &$rowCount) {
                    $products[] = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'slug' => $product->slug,
                        'brand' => $product->brand,
                        'description' => $product->description,
                        'category' => $product->category,
                        'canonical_category' => $product->canonical_category?->value,
                        'subcategory' => $product->subcategory,
                        'weight_grams' => $product->weight_grams,
                        'quantity' => $product->quantity,
                        'primary_image' => $product->primary_image,
                        'lowest_price_pence' => $product->lowest_price_pence,
                        'average_price_pence' => $product->average_price_pence,
                        'is_verified' => $product->is_verified,
                        'listings' => $product->productListings->map(function (ProductListing $listing) {
                            return [
                                'id' => $listing->id,
                                'retailer' => [
                                    'name' => $listing->retailer?->name,
                                    'slug' => $listing->retailer?->slug,
                                ],
                                'title' => $listing->title,
                                'price_pence' => $listing->price_pence,
                                'original_price_pence' => $listing->original_price_pence,
                                'in_stock' => $listing->in_stock,
                                'url' => $listing->url,
                                'last_scraped_at' => $listing->last_scraped_at?->toIso8601String(),
                            ];
                        })->toArray(),
                        'created_at' => $product->created_at?->toIso8601String(),
                        'updated_at' => $product->updated_at?->toIso8601String(),
                    ];
                    $rowCount++;
                });

            $jsonData = json_encode(['products' => $products, 'count' => $rowCount, 'exported_at' => now()->toIso8601String()], JSON_PRETTY_PRINT);
            if ($jsonData === false) {
                throw new \RuntimeException('Failed to encode JSON data');
            }

            Storage::put($filePath, $jsonData);

            $export->update([
                'status' => 'completed',
                'file_path' => $filePath,
                'file_name' => $fileName,
                'file_size_bytes' => Storage::size($filePath),
                'row_count' => $rowCount,
                'completed_at' => now(),
            ]);

            return $export->fresh();
        } catch (\Exception $e) {
            $export->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Build a product query with filters applied.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Builder<Product>
     */
    protected function buildProductQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = Product::query();

        if (isset($filters['brand']) && $filters['brand'] !== null) {
            $query->where('brand', $filters['brand']);
        }

        if (isset($filters['category']) && $filters['category'] !== null) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['canonical_category']) && $filters['canonical_category'] !== null) {
            $query->where('canonical_category', $filters['canonical_category']);
        }

        if (isset($filters['min_price']) && $filters['min_price'] !== null) {
            $query->where('lowest_price_pence', '>=', (int) $filters['min_price']);
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== null) {
            $query->where('lowest_price_pence', '<=', (int) $filters['max_price']);
        }

        if (isset($filters['verified']) && $filters['verified'] !== null) {
            $query->where('is_verified', (bool) $filters['verified']);
        }

        if (isset($filters['has_listings']) && $filters['has_listings'] === true) {
            $query->has('productListings');
        }

        $sortField = $filters['sort'] ?? 'name';
        $sortDirection = $filters['direction'] ?? 'asc';
        $allowedSortFields = ['name', 'brand', 'lowest_price_pence', 'created_at', 'updated_at'];

        if (in_array($sortField, $allowedSortFields, true)) {
            $query->orderBy($sortField, $sortDirection === 'desc' ? 'desc' : 'asc');
        }

        return $query;
    }

    /**
     * Create an export record in the database.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function createExportRecord(string $type, string $format, array $filters, ?int $userId): ProductExport
    {
        return ProductExport::create([
            'user_id' => $userId,
            'type' => $type,
            'format' => $format,
            'status' => 'pending',
            'filters' => $filters,
        ]);
    }

    /**
     * Generate a unique file name for an export.
     */
    protected function generateFileName(string $type, string $format): string
    {
        $timestamp = now()->format('Y-m-d_His');

        return "{$type}_{$timestamp}.{$format}";
    }

    /**
     * Clean up old export files (older than specified days).
     */
    public function cleanupOldExports(int $daysOld = 7): int
    {
        $cutoffDate = now()->subDays($daysOld);

        $exports = ProductExport::query()
            ->where('created_at', '<', $cutoffDate)
            ->whereNotNull('file_path')
            ->get();

        $deletedCount = 0;

        foreach ($exports as $export) {
            if (Storage::exists($export->file_path)) {
                Storage::delete($export->file_path);
            }

            $export->delete();
            $deletedCount++;
        }

        return $deletedCount;
    }
}
