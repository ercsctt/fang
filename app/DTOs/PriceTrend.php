<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PriceTrendIndicator;

readonly class PriceTrend
{
    public function __construct(
        public PriceTrendIndicator $indicator,
        public float $changePercentage,
        public int $startPricePence,
        public int $endPricePence,
        public int $periodDays,
    ) {}

    public function getFormattedChange(): string
    {
        $sign = $this->changePercentage >= 0 ? '+' : '';

        return $sign.number_format($this->changePercentage, 1).'%';
    }

    public function getFormattedStartPrice(): string
    {
        return '£'.number_format($this->startPricePence / 100, 2);
    }

    public function getFormattedEndPrice(): string
    {
        return '£'.number_format($this->endPricePence / 100, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'indicator' => $this->indicator->value,
            'indicator_label' => $this->indicator->label(),
            'indicator_icon' => $this->indicator->icon(),
            'indicator_color' => $this->indicator->color(),
            'change_percentage' => $this->changePercentage,
            'start_price_pence' => $this->startPricePence,
            'end_price_pence' => $this->endPricePence,
            'period_days' => $this->periodDays,
        ];
    }
}
