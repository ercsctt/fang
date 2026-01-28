<?php

declare(strict_types=1);

namespace App\Crawler\Scrapers;

use App\Crawler\Contracts\HttpAdapterInterface;
use Generator;
use Illuminate\Support\Facades\Log;

abstract class BaseCrawler
{
    /** @var array<string> */
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

            foreach ($this->extractors as $class) {
                $extractor = app()->make($class);
                if ($extractor->canHandle($url)) {
                    Log::info('Running extractor: '.get_class($extractor));

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
    public function addExtractor(string $class): self
    {
        $this->extractors[] = $class;

        return $this;
    }

    /**
     * Get extractors registered with this crawler.
     *
     * @return array<string>
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
     * Get the retailer slug for configuration lookup.
     *
     * Defaults to a snake_case version of the class name without "Crawler".
     * Override this method if you need a different slug.
     */
    public function getRetailerSlug(): string
    {
        $className = class_basename($this);
        $slug = str_replace('Crawler', '', $className);

        return strtolower($slug);
    }

    /**
     * Get the starting URLs to crawl for this retailer.
     *
     * @return array<string>
     */
    abstract public function getStartingUrls(): array;

    /**
     * Get custom request options for this crawler.
     *
     * Reads from config/crawler.php based on retailer slug.
     *
     * @return array<string, mixed>
     */
    protected function getRequestOptions(): array
    {
        $slug = $this->getRetailerSlug();
        $headers = config("crawler.retailers.{$slug}.headers", config('crawler.default_headers', []));

        return empty($headers) ? [] : ['headers' => $headers];
    }

    /**
     * Get the delay in milliseconds between requests.
     *
     * Reads from config/crawler.php based on retailer slug.
     */
    public function getRequestDelay(): int
    {
        $slug = $this->getRetailerSlug();

        return config("crawler.retailers.{$slug}.request_delay", config('crawler.default_delay', 1000));
    }
}
