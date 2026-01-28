<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\RetailerStatus;
use App\Events\RetailerStatusChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DisableRetailerRequest;
use App\Http\Requests\Admin\EnableRetailerRequest;
use App\Http\Requests\Admin\PauseRetailerRequest;
use App\Http\Requests\Admin\ResumeRetailerRequest;
use App\Models\Retailer;
use Illuminate\Http\JsonResponse;

class RetailerStatusController extends Controller
{
    /**
     * Pause a retailer temporarily.
     */
    public function pause(PauseRetailerRequest $request, Retailer $retailer): JsonResponse
    {
        $oldStatus = $retailer->status;

        $retailer->status = RetailerStatus::Paused;
        $retailer->paused_until = $request->input('duration_minutes')
            ? now()->addMinutes((int) $request->input('duration_minutes'))
            : now()->addHours(24); // Default 24 hours

        $retailer->save();

        event(new RetailerStatusChanged(
            retailer: $retailer,
            oldStatus: $oldStatus,
            newStatus: RetailerStatus::Paused,
            reason: $request->input('reason'),
            triggeredBy: $request->user()
        ));

        return response()->json([
            'message' => 'Retailer paused successfully',
            'retailer' => $this->formatRetailerResponse($retailer),
        ]);
    }

    /**
     * Resume a paused retailer.
     */
    public function resume(ResumeRetailerRequest $request, Retailer $retailer): JsonResponse
    {
        $oldStatus = $retailer->status;

        $retailer->status = RetailerStatus::Active;
        $retailer->paused_until = null;
        $retailer->save();

        event(new RetailerStatusChanged(
            retailer: $retailer,
            oldStatus: $oldStatus,
            newStatus: RetailerStatus::Active,
            reason: 'Manually resumed',
            triggeredBy: $request->user()
        ));

        return response()->json([
            'message' => 'Retailer resumed successfully',
            'retailer' => $this->formatRetailerResponse($retailer),
        ]);
    }

    /**
     * Disable a retailer.
     */
    public function disable(DisableRetailerRequest $request, Retailer $retailer): JsonResponse
    {
        $oldStatus = $retailer->status;

        $retailer->status = RetailerStatus::Disabled;
        $retailer->paused_until = null; // Clear any pause when disabling
        $retailer->save();

        event(new RetailerStatusChanged(
            retailer: $retailer,
            oldStatus: $oldStatus,
            newStatus: RetailerStatus::Disabled,
            reason: $request->input('reason'),
            triggeredBy: $request->user()
        ));

        return response()->json([
            'message' => 'Retailer disabled successfully',
            'retailer' => $this->formatRetailerResponse($retailer),
        ]);
    }

    /**
     * Enable a retailer.
     */
    public function enable(EnableRetailerRequest $request, Retailer $retailer): JsonResponse
    {
        $oldStatus = $retailer->status;

        $retailer->status = RetailerStatus::Active;
        $retailer->paused_until = null; // Clear any pause when enabling
        $retailer->consecutive_failures = 0; // Reset failure count
        $retailer->save();

        event(new RetailerStatusChanged(
            retailer: $retailer,
            oldStatus: $oldStatus,
            newStatus: RetailerStatus::Active,
            reason: 'Manually enabled',
            triggeredBy: $request->user()
        ));

        return response()->json([
            'message' => 'Retailer enabled successfully',
            'retailer' => $this->formatRetailerResponse($retailer),
        ]);
    }

    /**
     * Format retailer for JSON response.
     *
     * @return array<string, mixed>
     */
    private function formatRetailerResponse(Retailer $retailer): array
    {
        return [
            'id' => $retailer->id,
            'name' => $retailer->name,
            'slug' => $retailer->slug,
            'status' => $retailer->status->value,
            'status_label' => $retailer->status->label(),
            'status_color' => $retailer->status->color(),
            'status_description' => $retailer->status->description(),
            'consecutive_failures' => $retailer->consecutive_failures,
            'last_failure_at' => $retailer->last_failure_at?->toIso8601String(),
            'paused_until' => $retailer->paused_until?->toIso8601String(),
            'last_crawled_at' => $retailer->last_crawled_at?->toIso8601String(),
            'is_paused' => $retailer->isPaused(),
            'is_available_for_crawling' => $retailer->isAvailableForCrawling(),
        ];
    }
}
