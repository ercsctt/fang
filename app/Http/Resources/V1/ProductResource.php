<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
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
            'brand' => $this->brand,
            'description' => $this->description,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'weight_grams' => $this->weight_grams,
            'quantity' => $this->quantity,
            'primary_image' => $this->primary_image,
            'average_price_pence' => $this->average_price_pence,
            'average_price_formatted' => $this->average_price_formatted,
            'lowest_price_pence' => $this->lowest_price_pence,
            'lowest_price_formatted' => $this->lowest_price_formatted,
            'is_verified' => $this->is_verified,
            'listings_count' => $this->whenCounted('productListings'),
            'listings' => ProductListingResource::collection($this->whenLoaded('productListings')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
