<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CanonicalCategory;
use Carbon\Carbon;

readonly class CategoryPriceReport
{
    /**
     * @param  list<array{product_listing_id: int, title: string, current_price_pence: int, previous_price_pence: int, change_percentage: float}>  $topPriceDrops
     * @param  list<array{product_listing_id: int, title: string, current_price_pence: int, previous_price_pence: int, change_percentage: float}>  $topPriceIncreases
     */
    public function __construct(
        public CanonicalCategory $category,
        public Carbon $periodStart,
        public Carbon $periodEnd,
        public int $totalListings,
        public int $averagePricePence,
        public int $minPricePence,
        public int $maxPricePence,
        public float $averagePriceChange,
        public int $listingsOnSale,
        public float $salePercentage,
        public array $topPriceDrops,
        public array $topPriceIncreases,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'period_start' => $this->periodStart->format('Y-m-d'),
            'period_end' => $this->periodEnd->format('Y-m-d'),
            'total_listings' => $this->totalListings,
            'average_price_pence' => $this->averagePricePence,
            'min_price_pence' => $this->minPricePence,
            'max_price_pence' => $this->maxPricePence,
            'average_price_change' => $this->averagePriceChange,
            'listings_on_sale' => $this->listingsOnSale,
            'sale_percentage' => $this->salePercentage,
            'top_price_drops' => $this->topPriceDrops,
            'top_price_increases' => $this->topPriceIncreases,
        ];
    }
}
