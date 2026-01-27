<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ProductExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScheduledProductExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public string $type,
        public string $format,
        public array $filters = [],
        public ?int $userId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ProductExportService $exportService): void
    {
        try {
            Log::info("Starting scheduled export: {$this->type} in {$this->format} format", [
                'filters' => $this->filters,
                'user_id' => $this->userId,
            ]);

            $export = match ("{$this->type}_{$this->format}") {
                'products_csv' => $exportService->exportProductsToCsv($this->filters, $this->userId),
                'products_json' => $exportService->exportProductsToJson($this->filters, $this->userId),
                'prices_csv' => $exportService->exportProductsWithPricesToCsv($this->filters, $this->userId),
                default => throw new \InvalidArgumentException('Invalid export type/format combination'),
            };

            Log::info("Completed scheduled export: {$export->id}", [
                'type' => $this->type,
                'format' => $this->format,
                'row_count' => $export->row_count,
                'file_size' => $export->file_size_bytes,
            ]);
        } catch (\Exception $e) {
            Log::error('Scheduled export failed', [
                'type' => $this->type,
                'format' => $this->format,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
