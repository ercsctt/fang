<?php

declare(strict_types=1);

namespace App\Domain\Crawler\Aggregates;

use App\Domain\Crawler\Events\CrawlCompleted;
use App\Domain\Crawler\Events\CrawlFailed;
use App\Domain\Crawler\Events\CrawlStarted;
use App\Domain\Crawler\Events\ProductListingDiscovered;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class CrawlAggregate extends AggregateRoot
{
    private string $url;
    private string $retailer;
    private int $productListingsDiscovered = 0;
    private bool $isCompleted = false;
    private bool $hasFailed = false;

    public function startCrawl(string $url, string $retailer, array $metadata = []): self
    {
        $this->recordThat(new CrawlStarted(
            crawlId: $this->uuid(),
            url: $url,
            retailer: $retailer,
            metadata: $metadata,
        ));

        return $this;
    }

    public function recordProductListingDiscovered(
        string $url,
        string $retailer,
        ?string $category = null,
        array $metadata = []
    ): self {
        if ($this->hasFailed) {
            throw new \Exception("Cannot record product listing for failed crawl");
        }

        $this->recordThat(new ProductListingDiscovered(
            crawlId: $this->uuid(),
            url: $url,
            retailer: $retailer,
            category: $category,
            metadata: $metadata,
        ));

        return $this;
    }

    public function completeCrawl(array $statistics = []): self
    {
        if ($this->isCompleted) {
            return $this;
        }

        if ($this->hasFailed) {
            throw new \Exception("Cannot complete a failed crawl");
        }

        $this->recordThat(new CrawlCompleted(
            crawlId: $this->uuid(),
            productListingsDiscovered: $this->productListingsDiscovered,
            statistics: $statistics,
        ));

        return $this;
    }

    public function markAsFailed(string $reason, array $context = []): self
    {
        if ($this->hasFailed) {
            return $this;
        }

        $this->recordThat(new CrawlFailed(
            crawlId: $this->uuid(),
            reason: $reason,
            context: $context,
        ));

        return $this;
    }

    // Event handlers to update internal state

    protected function applyCrawlStarted(CrawlStarted $event): void
    {
        $this->url = $event->url;
        $this->retailer = $event->retailer;
    }

    protected function applyProductListingDiscovered(ProductListingDiscovered $event): void
    {
        $this->productListingsDiscovered++;
    }

    protected function applyCrawlCompleted(CrawlCompleted $event): void
    {
        $this->isCompleted = true;
    }

    protected function applyCrawlFailed(CrawlFailed $event): void
    {
        $this->hasFailed = true;
    }
}
