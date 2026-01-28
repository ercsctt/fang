<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class PriceStatistics
{
    public function __construct(
        public int $averagePricePence,
        public int $minPricePence,
        public int $maxPricePence,
        public int $priceRangePence,
        public float $standardDeviation,
        public int $dataPointCount,
        public int $periodDays,
    ) {}

    public function getFormattedAveragePrice(): string
    {
        return '£'.number_format($this->averagePricePence / 100, 2);
    }

    public function getFormattedMinPrice(): string
    {
        return '£'.number_format($this->minPricePence / 100, 2);
    }

    public function getFormattedMaxPrice(): string
    {
        return '£'.number_format($this->maxPricePence / 100, 2);
    }

    public function getFormattedPriceRange(): string
    {
        return '£'.number_format($this->priceRangePence / 100, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'average_price_pence' => $this->averagePricePence,
            'min_price_pence' => $this->minPricePence,
            'max_price_pence' => $this->maxPricePence,
            'price_range_pence' => $this->priceRangePence,
            'standard_deviation' => $this->standardDeviation,
            'data_point_count' => $this->dataPointCount,
            'period_days' => $this->periodDays,
        ];
    }
}
