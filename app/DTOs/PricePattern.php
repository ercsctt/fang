<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\Carbon;

readonly class PricePattern
{
    /**
     * @param  list<Carbon>  $saleDates
     */
    public function __construct(
        public string $patternType,
        public int $averageSaleDiscountPercentage,
        public int $saleFrequencyDays,
        public array $saleDates,
        public ?string $description = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pattern_type' => $this->patternType,
            'average_sale_discount_percentage' => $this->averageSaleDiscountPercentage,
            'sale_frequency_days' => $this->saleFrequencyDays,
            'sale_dates' => array_map(fn (Carbon $date) => $date->format('Y-m-d'), $this->saleDates),
            'description' => $this->description,
        ];
    }
}
