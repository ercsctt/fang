<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductExport;
use App\Services\ProductExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(
        protected ProductExportService $exportService
    ) {}

    /**
     * Get list of exports for authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $exports = ProductExport::query()
            ->when($request->user(), function ($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($exports);
    }

    /**
     * Create a new product export.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:products,prices',
            'format' => 'required|in:csv,json',
            'filters' => 'sometimes|array',
            'filters.brand' => 'sometimes|string',
            'filters.category' => 'sometimes|string',
            'filters.canonical_category' => 'sometimes|string',
            'filters.min_price' => 'sometimes|integer|min:0',
            'filters.max_price' => 'sometimes|integer|min:0',
            'filters.verified' => 'sometimes|boolean',
            'filters.has_listings' => 'sometimes|boolean',
            'filters.sort' => 'sometimes|string|in:name,brand,lowest_price_pence,created_at,updated_at',
            'filters.direction' => 'sometimes|string|in:asc,desc',
        ]);

        $filters = $validated['filters'] ?? [];
        $userId = $request->user()?->id;

        try {
            $export = match ("{$validated['type']}_{$validated['format']}") {
                'products_csv' => $this->exportService->exportProductsToCsv($filters, $userId),
                'products_json' => $this->exportService->exportProductsToJson($filters, $userId),
                'prices_csv' => $this->exportService->exportProductsWithPricesToCsv($filters, $userId),
                default => throw new \InvalidArgumentException('Invalid export type/format combination'),
            };

            return response()->json([
                'message' => 'Export created successfully',
                'export' => $export,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Export failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show details of a specific export.
     */
    public function show(Request $request, ProductExport $export): JsonResponse
    {
        if ($request->user() && $export->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($export);
    }

    /**
     * Download an export file.
     */
    public function download(Request $request, ProductExport $export): StreamedResponse|JsonResponse
    {
        if ($request->user() && $export->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $export->isCompleted()) {
            return response()->json([
                'message' => 'Export is not ready for download',
                'status' => $export->status,
            ], 400);
        }

        if (! $export->file_path || ! Storage::exists($export->file_path)) {
            return response()->json(['message' => 'Export file not found'], 404);
        }

        return Storage::download($export->file_path, $export->file_name);
    }

    /**
     * Delete an export.
     */
    public function destroy(Request $request, ProductExport $export): JsonResponse
    {
        if ($request->user() && $export->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($export->file_path && Storage::exists($export->file_path)) {
            Storage::delete($export->file_path);
        }

        $export->delete();

        return response()->json(['message' => 'Export deleted successfully']);
    }
}
