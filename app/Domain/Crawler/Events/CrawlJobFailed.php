<?php

declare(strict_types=1);

namespace App\Domain\Crawler\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CrawlJobFailed extends ShouldBeStored
{
    public function __construct(
        public readonly string $crawlId,
        public readonly string $retailerSlug,
        public readonly string $url,
        public readonly string $errorMessage,
        public readonly int $attemptNumber,
    ) {}
}
