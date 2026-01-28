<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ImageCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupOrphanedImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public int $daysOld = 30
    ) {}

    public function handle(ImageCacheService $imageCacheService): void
    {
        Log::info('Starting orphaned image cleanup', [
            'days_old' => $this->daysOld,
        ]);

        try {
            $deletedCount = $imageCacheService->cleanupOrphanedImages($this->daysOld);

            Log::info('Orphaned image cleanup completed', [
                'deleted_count' => $deletedCount,
            ]);
        } catch (\Exception $e) {
            Log::error('Orphaned image cleanup failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'image-cache',
            'cleanup',
        ];
    }
}
