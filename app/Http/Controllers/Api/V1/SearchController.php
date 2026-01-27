<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SearchProductsRequest;
use App\Http\Resources\V1\ProductResource;
use App\Services\Search\ProductSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class SearchController extends Controller
{
    public function __construct(
        private readonly ProductSearchService $searchService
    ) {}

    public function __invoke(SearchProductsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->toFilters();
        $results = $this->searchService->search($filters);

        return ProductResource::collection($results);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $query = $request->input('q', '');

        if (strlen($query) < 2) {
            throw ValidationException::withMessages([
                'q' => ['Search query must be at least 2 characters.'],
            ]);
        }

        $limit = min((int) $request->input('limit', 10), 20);
        $suggestions = $this->searchService->suggestions($query, $limit);

        return response()->json([
            'data' => $suggestions,
        ]);
    }

    public function filters(): JsonResponse
    {
        $options = $this->searchService->getFilterOptions();

        return response()->json([
            'data' => $options,
        ]);
    }
}
