<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePriceAlertRequest;
use App\Http\Resources\V1\PriceAlertResource;
use App\Models\PriceAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PriceAlertController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $alerts = $request->user()
            ->priceAlerts()
            ->with('product')
            ->latest()
            ->paginate(
                perPage: min((int) $request->input('per_page', 15), 100)
            );

        return PriceAlertResource::collection($alerts);
    }

    public function store(StorePriceAlertRequest $request): PriceAlertResource
    {
        $alert = $request->user()->priceAlerts()->create([
            'product_id' => $request->validated('product_id'),
            'target_price_pence' => $request->validated('target_price_pence'),
        ]);

        $alert->refresh()->load('product');

        return new PriceAlertResource($alert);
    }

    public function destroy(Request $request, PriceAlert $alert): JsonResponse
    {
        if ($alert->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to delete this alert.');
        }

        $alert->delete();

        return response()->json(['message' => 'Price alert deleted successfully.']);
    }
}
