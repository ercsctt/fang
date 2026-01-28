<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RetailerResource;
use App\Models\Retailer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RetailerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Retailer::query()
            ->withCount('productListings');

        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        if ($request->filled('health_status')) {
            $query->where('health_status', $request->input('health_status'));
        }

        $sortField = $request->input('sort', 'name');
        $sortDirection = $request->input('direction', 'asc');
        $allowedSortFields = ['name', 'last_crawled_at', 'created_at'];

        if (in_array($sortField, $allowedSortFields, true)) {
            $query->orderBy($sortField, $sortDirection === 'desc' ? 'desc' : 'asc');
        }

        $retailers = $query->paginate(
            perPage: min((int) $request->input('per_page', 15), 100)
        );

        return RetailerResource::collection($retailers);
    }
}
