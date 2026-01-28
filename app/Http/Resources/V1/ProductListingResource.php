<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\ProductListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductListing
 */
class ProductListingResource extends JsonResource
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
            'external_id' => $this->external_id,
            'url' => $this->url,
            'title' => $this->title,
            'description' => $this->description,
            'price_pence' => $this->price_pence,
            'price_formatted' => $this->price_formatted,
            'original_price_pence' => $this->original_price_pence,
            'currency' => $this->currency,
            'is_on_sale' => $this->isOnSale(),
            'weight_grams' => $this->weight_grams,
            'quantity' => $this->quantity,
            'brand' => $this->brand,
            'category' => $this->category,
            'images' => $this->getTransformedImages(),
            'ingredients' => $this->ingredients,
            'nutritional_info' => $this->nutritional_info,
            'in_stock' => $this->in_stock,
            'stock_quantity' => $this->stock_quantity,
            'retailer' => new RetailerResource($this->whenLoaded('retailer')),
            'prices' => PriceHistoryResource::collection($this->whenLoaded('prices')),
            'last_scraped_at' => $this->last_scraped_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Transform images to prioritize cached URLs with lazy loading support.
     *
     * @return array<array{url: string, cached_url: string|null, alt_text: string|null, is_primary: bool, width: int|null, height: int|null, placeholder_url: string}>|null
     */
    private function getTransformedImages(): ?array
    {
        if (! $this->images) {
            return null;
        }

        $placeholderUrl = config('app.url').'/images/placeholder.svg';

        return array_map(function ($image) use ($placeholderUrl) {
            if (is_string($image)) {
                return [
                    'url' => $image,
                    'cached_url' => null,
                    'alt_text' => null,
                    'is_primary' => false,
                    'width' => null,
                    'height' => null,
                    'placeholder_url' => $placeholderUrl,
                ];
            }

            return [
                'url' => $image['url'] ?? $image['cached_url'] ?? '',
                'cached_url' => $image['cached_url'] ?? null,
                'alt_text' => $image['alt_text'] ?? null,
                'is_primary' => $image['is_primary'] ?? false,
                'width' => $image['width'] ?? null,
                'height' => $image['height'] ?? null,
                'placeholder_url' => $placeholderUrl,
            ];
        }, $this->images);
    }
}
