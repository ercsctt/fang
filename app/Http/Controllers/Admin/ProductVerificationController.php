<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveMatchRequest;
use App\Http\Requests\Admin\BulkApproveMatchesRequest;
use App\Http\Requests\Admin\RejectMatchRequest;
use App\Http\Requests\Admin\RematchProductListingRequest;
use App\Models\Product;
use App\Models\ProductListingMatch;
use App\Models\UserVerificationStat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductVerificationController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->input('status', 'pending');
        $sortField = $request->input('sort', 'confidence_score');
        $sortDirection = $request->input('direction', 'asc');

        $query = ProductListingMatch::query()
            ->with([
                'product:id,name,slug,brand,primary_image,weight_grams,quantity',
                'productListing:id,retailer_id,title,brand,url,price_pence,images,weight_grams,quantity',
                'productListing.retailer:id,name,slug',
                'verifier:id,name',
            ]);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $query->orderBy($sortField, $sortDirection);

        $matches = $query->paginate(20)->withQueryString();

        $stats = $this->getVerificationStats();

        return Inertia::render('Admin/ProductVerification/Index', [
            'matches' => $matches,
            'stats' => $stats,
            'filters' => [
                'status' => $status,
                'sort' => $sortField,
                'direction' => $sortDirection,
            ],
        ]);
    }

    public function show(ProductListingMatch $match): Response
    {
        $match->load([
            'product:id,name,slug,brand,description,primary_image,weight_grams,quantity,category,subcategory',
            'productListing:id,retailer_id,title,brand,description,url,price_pence,images,weight_grams,quantity,category,ingredients',
            'productListing.retailer:id,name,slug',
            'verifier:id,name',
        ]);

        $otherMatches = ProductListingMatch::query()
            ->where('product_id', $match->product_id)
            ->where('id', '!=', $match->id)
            ->with(['productListing:id,retailer_id,title,url,price_pence', 'productListing.retailer:id,name'])
            ->limit(10)
            ->get();

        $suggestedProducts = Product::query()
            ->where('id', '!=', $match->product_id)
            ->where(function ($query) use ($match) {
                $query->where('brand', $match->productListing->brand)
                    ->orWhere('name', 'LIKE', '%'.($match->productListing->brand ?? '').'%');
            })
            ->limit(10)
            ->get(['id', 'name', 'slug', 'brand', 'primary_image']);

        return Inertia::render('Admin/ProductVerification/Show', [
            'match' => $match,
            'otherMatches' => $otherMatches,
            'suggestedProducts' => $suggestedProducts,
        ]);
    }

    public function approve(ApproveMatchRequest $request, ProductListingMatch $match): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $match->approve($user);

        $stats = UserVerificationStat::forUserToday($user);
        $stats->incrementApproved();

        return redirect()->back()->with('success', 'Match approved successfully.');
    }

    public function reject(RejectMatchRequest $request, ProductListingMatch $match): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $match->reject($user, $request->validated('reason'));

        $stats = UserVerificationStat::forUserToday($user);
        $stats->incrementRejected();

        return redirect()->back()->with('success', 'Match rejected successfully.');
    }

    public function rematch(RematchProductListingRequest $request, ProductListingMatch $match): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $newProductId = $request->validated('product_id');

        $match->update([
            'product_id' => $newProductId,
            'confidence_score' => 100.0,
            'match_type' => \App\Enums\MatchType::Manual,
            'matched_at' => now(),
            'verified_by' => $user->id,
            'verified_at' => now(),
            'status' => VerificationStatus::Approved,
            'rejection_reason' => null,
        ]);

        $stats = UserVerificationStat::forUserToday($user);
        $stats->incrementRematched();

        return redirect()->back()->with('success', 'Product listing rematched successfully.');
    }

    public function bulkApprove(BulkApproveMatchesRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $minConfidence = $request->validated('min_confidence', 95.0);
        $limit = $request->validated('limit', 100);

        $matches = ProductListingMatch::query()
            ->pending()
            ->highConfidence($minConfidence)
            ->limit($limit)
            ->get();

        $approvedCount = 0;

        foreach ($matches as $match) {
            $match->approve($user);
            $approvedCount++;
        }

        if ($approvedCount > 0) {
            $stats = UserVerificationStat::forUserToday($user);
            $stats->incrementApproved($approvedCount);
            $stats->incrementBulkApprovals();
        }

        return response()->json([
            'message' => "Successfully approved {$approvedCount} matches.",
            'approved_count' => $approvedCount,
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $userStats = UserVerificationStat::query()
            ->forUser($user)
            ->betweenDates(now()->subDays(30), now())
            ->orderBy('date', 'desc')
            ->get();

        $todayStats = $userStats->first(fn ($stat) => $stat->date->isSameDay(today()));

        return response()->json([
            'today' => [
                'approved' => $todayStats?->matches_approved ?? 0,
                'rejected' => $todayStats?->matches_rejected ?? 0,
                'rematched' => $todayStats?->matches_rematched ?? 0,
                'bulk_approvals' => $todayStats?->bulk_approvals ?? 0,
                'total' => $todayStats?->total_verifications ?? 0,
            ],
            'history' => $userStats,
        ]);
    }

    /**
     * @return array{pending: int, approved: int, rejected: int, total: int, high_confidence_pending: int}
     */
    private function getVerificationStats(): array
    {
        $pending = ProductListingMatch::pending()->count();
        $approved = ProductListingMatch::approved()->count();
        $rejected = ProductListingMatch::rejected()->count();
        $highConfidencePending = ProductListingMatch::pending()->highConfidence(95.0)->count();

        return [
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'total' => $pending + $approved + $rejected,
            'high_confidence_pending' => $highConfidencePending,
        ];
    }
}
