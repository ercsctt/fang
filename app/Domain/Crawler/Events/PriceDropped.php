<?php

declare(strict_types=1);

namespace App\Domain\Crawler\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PriceDropped extends ShouldBeStored
{
    public function __construct(
        public readonly int $productListingId,
        public readonly string $productTitle,
        public readonly string $retailerName,
        public readonly string $productUrl,
        public readonly int $oldPricePence,
        public readonly int $newPricePence,
        public readonly float $dropPercentage,
    ) {}
}
