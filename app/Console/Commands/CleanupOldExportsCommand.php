<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ProductExportService;
use Illuminate\Console\Command;

class CleanupOldExportsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exports:cleanup {--days=7 : Number of days to keep exports}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old export files';

    /**
     * Execute the console command.
     */
    public function handle(ProductExportService $exportService): int
    {
        $days = (int) $this->option('days');

        $this->info("Cleaning up exports older than {$days} days...");

        $deletedCount = $exportService->cleanupOldExports($days);

        $this->info("Deleted {$deletedCount} old export(s).");

        return self::SUCCESS;
    }
}
