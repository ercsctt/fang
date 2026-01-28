<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\CategoryNormalizer;
use Illuminate\Console\Command;

class BackfillProductCanonicalCategories extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:backfill-canonical-categories
                            {--chunk=100 : Number of products to process per batch}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill canonical_category for existing products based on their category and name';

    public function __construct(
        private readonly CategoryNormalizer $categoryNormalizer,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $totalProducts = Product::query()->count();

        if ($totalProducts === 0) {
            $this->info('No products found to backfill.');

            return self::SUCCESS;
        }

        $this->info("Found {$totalProducts} products to process.");

        if (! $this->option('force')) {
            if (! $this->confirm('Do you want to continue?', true)) {
                $this->info('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        $chunkSize = (int) $this->option('chunk');
        $updated = 0;
        $skipped = 0;

        $progressBar = $this->output->createProgressBar($totalProducts);
        $progressBar->start();

        Product::query()->chunk($chunkSize, function ($products) use (&$updated, &$skipped, $progressBar) {
            foreach ($products as $product) {
                $canonicalCategory = $this->categoryNormalizer->normalizeWithContext(
                    $product->category,
                    $product->name
                );

                // Only update if the canonical category has changed
                if ($product->canonical_category !== $canonicalCategory) {
                    $product->update(['canonical_category' => $canonicalCategory]);
                    $updated++;
                } else {
                    $skipped++;
                }

                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        $this->info('Backfill completed successfully!');
        $this->table(
            ['Status', 'Count'],
            [
                ['Updated', $updated],
                ['Skipped (no change)', $skipped],
                ['Total', $totalProducts],
            ]
        );

        return self::SUCCESS;
    }
}
