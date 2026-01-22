<?php

declare(strict_types=1);

namespace App\Domain\Crawler\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CrawlFailed extends ShouldBeStored
{
    public function __construct(
        public readonly string $crawlId,
        public readonly string $reason,
        public readonly array $context = [],
    ) {}
}
