<?php

declare(strict_types=1);

namespace App\Domain\Crawler\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ProductListingDiscovered extends ShouldBeStored
{
    public function __construct(
        public readonly string $crawlId,
        public readonly string $url,
        public readonly string $retailer,
        public readonly ?string $category = null,
        public readonly array $metadata = [],
    ) {}
}
