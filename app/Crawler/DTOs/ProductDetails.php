<?php

declare(strict_types=1);

namespace App\Crawler\DTOs;

readonly class ProductDetails
{
    public function __construct(
        public string $title,
        public ?string $description,
        public ?string $brand,
        public int $pricePence,
        public ?int $originalPricePence,
        public string $currency = 'GBP',
        public ?int $weightGrams = null,
        public ?int $quantity = null,
        public array $images = [],
        public ?string $ingredients = null,
        public ?array $nutritionalInfo = null,
        public bool $inStock = true,
        public ?int $stockQuantity = null,
        public ?string $externalId = null,
        public ?string $category = null,
        public ?array $metadata = null,
        public ?string $barcode = null,
    ) {}

    public function hasDiscount(): bool
    {
        return $this->originalPricePence !== null
            && $this->originalPricePence > $this->pricePence;
    }

    public function getDiscountPercentage(): ?float
    {
        if (! $this->hasDiscount()) {
            return null;
        }

        return round(
            (($this->originalPricePence - $this->pricePence) / $this->originalPricePence) * 100,
            2
        );
    }

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'brand' => $this->brand,
            'price_pence' => $this->pricePence,
            'original_price_pence' => $this->originalPricePence,
            'currency' => $this->currency,
            'weight_grams' => $this->weightGrams,
            'quantity' => $this->quantity,
            'images' => $this->images,
            'ingredients' => $this->ingredients,
            'nutritional_info' => $this->nutritionalInfo,
            'in_stock' => $this->inStock,
            'stock_quantity' => $this->stockQuantity,
            'external_id' => $this->externalId,
            'category' => $this->category,
            'metadata' => $this->metadata,
            'barcode' => $this->barcode,
        ];
    }
}
