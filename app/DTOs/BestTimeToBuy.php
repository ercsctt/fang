<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class BestTimeToBuy
{
    /**
     * @param  list<string>  $recommendedDaysOfWeek
     */
    public function __construct(
        public bool $isOnSaleNow,
        public int $currentPricePence,
        public int $averagePricePence,
        public int $lowestHistoricalPricePence,
        public ?int $expectedDaysUntilSale,
        public array $recommendedDaysOfWeek,
        public string $recommendation,
    ) {}

    public function getCurrentSavingsPercentage(): ?float
    {
        if (! $this->isOnSaleNow || $this->averagePricePence <= 0) {
            return null;
        }

        return round(
            (($this->averagePricePence - $this->currentPricePence) / $this->averagePricePence) * 100,
            1
        );
    }

    public function getFormattedCurrentPrice(): string
    {
        return '£'.number_format($this->currentPricePence / 100, 2);
    }

    public function getFormattedAveragePrice(): string
    {
        return '£'.number_format($this->averagePricePence / 100, 2);
    }

    public function getFormattedLowestPrice(): string
    {
        return '£'.number_format($this->lowestHistoricalPricePence / 100, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_on_sale_now' => $this->isOnSaleNow,
            'current_price_pence' => $this->currentPricePence,
            'average_price_pence' => $this->averagePricePence,
            'lowest_historical_price_pence' => $this->lowestHistoricalPricePence,
            'current_savings_percentage' => $this->getCurrentSavingsPercentage(),
            'expected_days_until_sale' => $this->expectedDaysUntilSale,
            'recommended_days_of_week' => $this->recommendedDaysOfWeek,
            'recommendation' => $this->recommendation,
        ];
    }
}
