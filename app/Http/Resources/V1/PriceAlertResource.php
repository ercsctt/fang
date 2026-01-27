<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\PriceAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PriceAlert
 */
class PriceAlertResource extends JsonResource
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
            'product_id' => $this->product_id,
            'target_price_pence' => $this->target_price_pence,
            'target_price_formatted' => $this->target_price_formatted,
            'is_active' => $this->is_active,
            'last_notified_at' => $this->last_notified_at?->toIso8601String(),
            'product' => new ProductResource($this->whenLoaded('product')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
