<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PriceHistoryResource;
use App\Http\Resources\V1\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->withCount('productListings');

        if ($request->filled('brand')) {
            $query->where('brand', $request->input('brand'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('min_price')) {
            $query->where('lowest_price_pence', '>=', (int) $request->input('min_price'));
        }

        if ($request->filled('max_price')) {
            $query->where('lowest_price_pence', '<=', (int) $request->input('max_price'));
        }

        if ($request->filled('verified')) {
            $query->where('is_verified', $request->boolean('verified'));
        }

        $sortField = $request->input('sort', 'name');
        $sortDirection = $request->input('direction', 'asc');
        $allowedSortFields = ['name', 'brand', 'lowest_price_pence', 'created_at', 'updated_at'];

        if (in_array($sortField, $allowedSortFields, true)) {
            $query->orderBy($sortField, $sortDirection === 'desc' ? 'desc' : 'asc');
        }

        $products = $query->paginate(
            perPage: min((int) $request->input('per_page', 15), 100)
        );

        return ProductResource::collection($products);
    }

    public function show(Request $request, string $slug): ProductResource
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->with(['productListings' => function ($query) {
                $query->with('retailer')
                    ->orderBy('price_pence');
            }])
            ->firstOrFail();

        return new ProductResource($product);
    }

    public function priceHistory(Request $request, string $slug): AnonymousResourceCollection
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->firstOrFail();

        $listings = $product->productListings()
            ->with(['prices' => function ($query) use ($request) {
                $query->orderBy('recorded_at', 'desc');

                if ($request->filled('from')) {
                    $query->where('recorded_at', '>=', $request->input('from'));
                }

                if ($request->filled('to')) {
                    $query->where('recorded_at', '<=', $request->input('to'));
                }
            }, 'retailer'])
            ->get();

        $priceHistory = $listings->flatMap(function ($listing) {
            return $listing->prices->map(function ($price) use ($listing) {
                $price->listing_title = $listing->title;
                $price->retailer_name = $listing->retailer?->name;
                $price->retailer_slug = $listing->retailer?->slug;

                return $price;
            });
        })->sortByDesc('recorded_at')->values();

        return PriceHistoryResource::collection($priceHistory);
    }
}
