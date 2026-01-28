<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\ProductListingPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductListingPrice
 */
class PriceHistoryResource extends JsonResource
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
            'price_pence' => $this->price_pence,
            'price_formatted' => $this->price_formatted,
            'original_price_pence' => $this->original_price_pence,
            'currency' => $this->currency,
            'is_on_sale' => $this->isOnSale(),
            'listing_title' => $this->when(
                property_exists($this->resource, 'listing_title'),
                fn () => $this->resource->listing_title
            ),
            'retailer_name' => $this->when(
                property_exists($this->resource, 'retailer_name'),
                fn () => $this->resource->retailer_name
            ),
            'retailer_slug' => $this->when(
                property_exists($this->resource, 'retailer_slug'),
                fn () => $this->resource->retailer_slug
            ),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
        ];
    }
}
