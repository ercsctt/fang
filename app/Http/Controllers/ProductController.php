<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductListing;
use App\Models\Retailer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Product::query()
            ->whereHas('productListings');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        if ($request->filled('brand')) {
            $query->where('brand', $request->input('brand'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('retailer')) {
            $retailerId = (int) $request->input('retailer');
            $query->whereHas('productListings', function (Builder $q) use ($retailerId) {
                $q->where('retailer_id', $retailerId);
            });
        }

        if ($request->filled('min_price')) {
            $query->where('lowest_price_pence', '>=', (int) $request->input('min_price') * 100);
        }

        if ($request->filled('max_price')) {
            $query->where('lowest_price_pence', '<=', (int) $request->input('max_price') * 100);
        }

        $sortBy = $request->input('sort', 'name');
        $sortDir = $request->input('dir', 'asc');

        $query->orderBy(match ($sortBy) {
            'price' => 'lowest_price_pence',
            'name' => 'name',
            default => 'name',
        }, $sortDir === 'desc' ? 'desc' : 'asc');

        $products = $query->paginate(24)->withQueryString();

        $brands = Product::query()
            ->whereNotNull('brand')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand');

        $categories = Product::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $retailers = Retailer::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Products/Index', [
            'products' => $products,
            'filters' => [
                'search' => $request->input('search', ''),
                'brand' => $request->input('brand', ''),
                'category' => $request->input('category', ''),
                'retailer' => $request->input('retailer', ''),
                'min_price' => $request->input('min_price', ''),
                'max_price' => $request->input('max_price', ''),
                'sort' => $sortBy,
                'dir' => $sortDir,
            ],
            'brands' => $brands,
            'categories' => $categories,
            'retailers' => $retailers,
        ]);
    }

    public function show(Product $product): Response
    {
        $product->load([
            'productListings' => function ($query) {
                $query->with(['retailer', 'prices' => function ($q) {
                    $q->orderBy('recorded_at', 'desc');
                }])
                    ->where('in_stock', true)
                    ->orderBy('price_pence', 'asc');
            },
            'productListings.reviews' => function ($query) {
                $query->orderBy('review_date', 'desc')->limit(10);
            },
        ]);

        $priceHistory = $this->getPriceHistory($product);
        $allReviews = $this->getAllReviews($product);
        $averageRating = $this->getAverageRating($product);
        $totalReviewCount = $this->getTotalReviewCount($product);

        return Inertia::render('Products/Show', [
            'product' => $product,
            'priceHistory' => $priceHistory,
            'reviews' => $allReviews,
            'averageRating' => $averageRating,
            'totalReviewCount' => $totalReviewCount,
        ]);
    }

    public function home(): Response
    {
        $featuredProducts = Product::query()
            ->whereHas('productListings', function ($query) {
                $query->where('in_stock', true);
            })
            ->whereNotNull('primary_image')
            ->orderBy('updated_at', 'desc')
            ->limit(8)
            ->get();

        $priceDrops = $this->getRecentPriceDrops();

        $retailers = Retailer::query()
            ->active()
            ->withCount('productListings')
            ->orderBy('product_listings_count', 'desc')
            ->get();

        $productCount = Product::count();
        $listingCount = ProductListing::where('in_stock', true)->count();

        return Inertia::render('Products/Home', [
            'featuredProducts' => $featuredProducts,
            'priceDrops' => $priceDrops,
            'retailers' => $retailers,
            'stats' => [
                'productCount' => $productCount,
                'listingCount' => $listingCount,
                'retailerCount' => $retailers->count(),
            ],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->input('q', '');

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $products = Product::query()
            ->where(function (Builder $q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('brand', 'like', "%{$query}%");
            })
            ->whereHas('productListings')
            ->limit(10)
            ->get(['id', 'name', 'brand', 'slug', 'primary_image', 'lowest_price_pence']);

        return response()->json($products);
    }

    /**
     * @return array<int, array{date: string, prices: array<string, int>}>
     */
    private function getPriceHistory(Product $product): array
    {
        $history = [];

        foreach ($product->productListings as $listing) {
            $retailerName = $listing->retailer->name;

            foreach ($listing->prices as $price) {
                $date = $price->recorded_at->format('Y-m-d');

                if (! isset($history[$date])) {
                    $history[$date] = ['date' => $date, 'prices' => []];
                }

                $history[$date]['prices'][$retailerName] = $price->price_pence;
            }
        }

        ksort($history);

        return array_values($history);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\ProductListingReview>
     */
    private function getAllReviews(Product $product): \Illuminate\Support\Collection
    {
        return $product->productListings
            ->flatMap(fn ($listing) => $listing->reviews)
            ->sortByDesc('review_date')
            ->take(20)
            ->values();
    }

    private function getAverageRating(Product $product): ?float
    {
        $ratings = $product->productListings
            ->flatMap(fn ($listing) => $listing->reviews)
            ->pluck('rating')
            ->filter();

        if ($ratings->isEmpty()) {
            return null;
        }

        return round($ratings->avg(), 1);
    }

    private function getTotalReviewCount(Product $product): int
    {
        return $product->productListings
            ->sum(fn ($listing) => $listing->reviews->count());
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{product: Product, listing: ProductListing, drop_percentage: float}>
     */
    private function getRecentPriceDrops(): \Illuminate\Support\Collection
    {
        $listings = ProductListing::query()
            ->with(['retailer', 'products', 'prices' => function ($query) {
                $query->orderBy('recorded_at', 'desc')->limit(2);
            }])
            ->whereHas('prices', function ($query) {
                $query->where('recorded_at', '>=', now()->subDays(7));
            })
            ->where('in_stock', true)
            ->get();

        return $listings
            ->filter(function ($listing) {
                if ($listing->prices->count() < 2) {
                    return false;
                }

                $current = $listing->prices->first();
                $previous = $listing->prices->skip(1)->first();

                return $current && $previous && $current->price_pence < $previous->price_pence;
            })
            ->map(function ($listing) {
                $current = $listing->prices->first();
                $previous = $listing->prices->skip(1)->first();
                $dropPercentage = (($previous->price_pence - $current->price_pence) / $previous->price_pence) * 100;

                return [
                    'listing' => $listing,
                    'product' => $listing->products->first(),
                    'previous_price_pence' => $previous->price_pence,
                    'current_price_pence' => $current->price_pence,
                    'drop_percentage' => round($dropPercentage, 1),
                ];
            })
            ->sortByDesc('drop_percentage')
            ->take(8)
            ->values();
    }
}
