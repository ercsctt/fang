<?php

declare(strict_types=1);

namespace App\Crawler\Scrapers;

use App\Crawler\Contracts\ExtractorInterface;
use App\Crawler\Contracts\HttpAdapterInterface;
use Generator;
use Illuminate\Support\Facades\Log;

abstract class BaseCrawler
{
    /** @var array<ExtractorInterface> */
    protected array $extractors = [];

    public function __construct(
        protected readonly HttpAdapterInterface $httpAdapter,
    ) {}

    /**
     * Crawl a URL and extract data using registered extractors.
     *
     * @return Generator Yields DTOs from all extractors
     */
    public function crawl(string $url): Generator
    {
        Log::info("Crawling URL: {$url}");

        try {
            $html = $this->httpAdapter->fetchHtml($url, $this->getRequestOptions());

            Log::info("Successfully fetched HTML from {$url}", [
                'status_code' => $this->httpAdapter->getLastStatusCode(),
                'html_length' => strlen($html),
            ]);

            foreach ($this->extractors as $extractor) {
                if ($extractor->canHandle($url)) {
                    Log::info("Running extractor: " . get_class($extractor));

                    foreach ($extractor->extract($html, $url) as $dto) {
                        yield $dto;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to crawl URL: {$url}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Register an extractor with this crawler.
     */
    public function addExtractor(ExtractorInterface $extractor): self
    {
        $this->extractors[] = $extractor;
        return $this;
    }

    /**
     * Get extractors registered with this crawler.
     *
     * @return array<ExtractorInterface>
     */
    public function getExtractors(): array
    {
        return $this->extractors;
    }

    /**
     * Get the retailer name for this crawler.
     */
    abstract public function getRetailerName(): string;

    /**
     * Get the starting URLs to crawl for this retailer.
     *
     * @return array<string>
     */
    abstract public function getStartingUrls(): array;

    /**
     * Get custom request options for this crawler.
     *
     * @return array<string, mixed>
     */
    protected function getRequestOptions(): array
    {
        return [];
    }

    /**
     * Get the delay in milliseconds between requests.
     */
    public function getRequestDelay(): int
    {
        return 1000; // 1 second default
    }
}
