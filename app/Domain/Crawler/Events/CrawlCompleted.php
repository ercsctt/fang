<?php

declare(strict_types=1);

namespace App\Domain\Crawler\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CrawlCompleted extends ShouldBeStored
{
    public function __construct(
        public readonly string $crawlId,
        public readonly int $productListingsDiscovered,
        public readonly array $statistics = [],
    ) {}
}
