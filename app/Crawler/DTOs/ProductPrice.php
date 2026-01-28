<?php

declare(strict_types=1);

namespace App\Crawler\DTOs;

readonly class ProductPrice
{
    public function __construct(
        public int $pricePence,
        public ?int $originalPricePence = null,
        public string $currency = 'GBP',
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

    public function getFormattedPrice(): string
    {
        $symbol = match ($this->currency) {
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
            default => $this->currency.' ',
        };

        return $symbol.number_format($this->pricePence / 100, 2);
    }

    public function toArray(): array
    {
        return [
            'price_pence' => $this->pricePence,
            'original_price_pence' => $this->originalPricePence,
            'currency' => $this->currency,
        ];
    }
}
