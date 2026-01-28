<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Retailer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Retailer
 */
class RetailerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'base_url' => $this->base_url,
            'is_active' => $this->is_active,
            'health_status' => $this->health_status?->value,
            'health_status_label' => $this->health_status?->label(),
            'is_paused' => $this->isPaused(),
            'paused_until' => $this->paused_until?->toIso8601String(),
            'last_crawled_at' => $this->last_crawled_at?->toIso8601String(),
            'listings_count' => $this->whenCounted('productListings'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
