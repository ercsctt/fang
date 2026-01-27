<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProductListing;
use App\Models\Retailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class DataQualityReportCommand extends Command
{
    protected $signature = 'crawl:quality-report
                            {--retailer= : Filter by retailer slug}
                            {--export=  : Export results to file (csv)}';

    protected $description = 'Report on data quality and completeness across product listings';

    /**
     * Fields to check for completeness calculation.
     *
     * @var list<string>
     */
    protected array $completenessFields = [
        'title',
        'description',
        'price_pence',
        'brand',
        'images',
        'ingredients',
    ];

    public function handle(): int
    {
        info('Data Quality Report - '.now()->format('Y-m-d H:i:s'));
        $this->newLine();

        $retailerSlug = $this->option('retailer');
        $exportFormat = $this->option('export');

        $data = $this->gatherQualityData($retailerSlug);

        if (empty($data)) {
            warning('No retailers or listings found.');

            return self::SUCCESS;
        }

        if ($exportFormat === 'csv') {
            return $this->exportToCsv($data);
        }

        $this->displayReport($data);

        return self::SUCCESS;
    }

    /**
     * Gather quality data for all retailers or a specific one.
     *
     * @return list<array<string, mixed>>
     */
    protected function gatherQualityData(?string $retailerSlug): array
    {
        $query = Retailer::query()->with('productListings');

        if ($retailerSlug !== null) {
            $query->where('slug', $retailerSlug);
        }

        $retailers = $query->get();

        $data = [];
        foreach ($retailers as $retailer) {
            $stats = $this->getRetailerStats($retailer);
            if ($stats !== null) {
                $data[] = $stats;
            }
        }

        return $data;
    }

    /**
     * Get statistics for a single retailer.
     *
     * @return array<string, mixed>|null
     */
    protected function getRetailerStats(Retailer $retailer): ?array
    {
        $baseStats = ProductListing::query()
            ->where('retailer_id', $retailer->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN price_pence IS NULL THEN 1 ELSE 0 END) as missing_price')
            ->selectRaw("SUM(CASE WHEN description IS NULL OR description = '' THEN 1 ELSE 0 END) as missing_description")
            ->selectRaw("SUM(CASE WHEN images IS NULL OR images = '[]' OR images = '' THEN 1 ELSE 0 END) as missing_images")
            ->selectRaw("SUM(CASE WHEN brand IS NULL OR brand = '' THEN 1 ELSE 0 END) as missing_brand")
            ->selectRaw("SUM(CASE WHEN ingredients IS NULL OR ingredients = '' THEN 1 ELSE 0 END) as missing_ingredients")
            ->selectRaw("SUM(CASE WHEN title IS NULL OR title = '' THEN 1 ELSE 0 END) as missing_title")
            ->first();

        if ($baseStats === null || $baseStats->total === 0) {
            return null;
        }

        $staleCount = ProductListing::query()
            ->where('retailer_id', $retailer->id)
            ->where(function ($query) {
                $query->whereNull('last_scraped_at')
                    ->orWhere('last_scraped_at', '<', now()->subHours(48));
            })
            ->count();

        $priceAnomalies = $this->detectPriceAnomalies($retailer->id);

        $completenessScore = $this->calculateCompletenessScore($baseStats);

        return [
            'retailer_name' => $retailer->name,
            'retailer_slug' => $retailer->slug,
            'total_listings' => (int) $baseStats->total,
            'missing_price' => (int) $baseStats->missing_price,
            'missing_description' => (int) $baseStats->missing_description,
            'missing_images' => (int) $baseStats->missing_images,
            'missing_brand' => (int) $baseStats->missing_brand,
            'missing_ingredients' => (int) $baseStats->missing_ingredients,
            'missing_title' => (int) $baseStats->missing_title,
            'stale_listings' => $staleCount,
            'price_anomalies' => $priceAnomalies,
            'completeness_score' => $completenessScore,
        ];
    }

    /**
     * Detect price anomalies (prices that changed >50%).
     *
     * @return list<array<string, mixed>>
     */
    protected function detectPriceAnomalies(int $retailerId): array
    {
        // Get listings with price history that have >50% change
        $anomalies = DB::table('product_listing_prices as p1')
            ->join('product_listing_prices as p2', function ($join) {
                $join->on('p1.product_listing_id', '=', 'p2.product_listing_id')
                    ->whereColumn('p1.recorded_at', '<', 'p2.recorded_at');
            })
            ->join('product_listings as pl', 'pl.id', '=', 'p1.product_listing_id')
            ->where('pl.retailer_id', $retailerId)
            ->where('p1.price_pence', '>', 0)
            ->whereRaw('ABS(p2.price_pence - p1.price_pence) * 100.0 / p1.price_pence > 50')
            ->select([
                'pl.id as listing_id',
                'pl.title',
                'p1.price_pence as old_price',
                'p2.price_pence as new_price',
                'p1.recorded_at as old_date',
                'p2.recorded_at as new_date',
            ])
            ->orderByDesc('p2.recorded_at')
            ->limit(10)
            ->get();

        return $anomalies->map(function ($row) {
            $changePercent = round(
                (($row->new_price - $row->old_price) / $row->old_price) * 100,
                1
            );

            return [
                'listing_id' => $row->listing_id,
                'title' => $row->title,
                'old_price_pence' => $row->old_price,
                'new_price_pence' => $row->new_price,
                'change_percent' => $changePercent,
            ];
        })->toArray();
    }

    /**
     * Calculate completeness score as percentage.
     */
    protected function calculateCompletenessScore(object $stats): float
    {
        if ($stats->total === 0) {
            return 0.0;
        }

        $totalFields = count($this->completenessFields);
        $total = (int) $stats->total;

        // Calculate total missing fields
        $missingFields = (int) $stats->missing_title +
            (int) $stats->missing_description +
            (int) $stats->missing_price +
            (int) $stats->missing_brand +
            (int) $stats->missing_images +
            (int) $stats->missing_ingredients;

        // Total possible data points
        $totalDataPoints = $total * $totalFields;

        // Fields with data
        $fieldsWithData = $totalDataPoints - $missingFields;

        return round(($fieldsWithData / $totalDataPoints) * 100, 1);
    }

    /**
     * Display the report using Laravel Prompts.
     *
     * @param  list<array<string, mixed>>  $data
     */
    protected function displayReport(array $data): void
    {
        // Summary table
        note('Summary by Retailer');
        $summaryRows = [];
        foreach ($data as $retailerData) {
            $summaryRows[] = [
                $retailerData['retailer_name'],
                (string) $retailerData['total_listings'],
                $retailerData['completeness_score'].'%',
                (string) $retailerData['stale_listings'],
                (string) count($retailerData['price_anomalies']),
            ];
        }
        table(
            ['Retailer', 'Listings', 'Completeness', 'Stale (>48h)', 'Price Anomalies'],
            $summaryRows
        );
        $this->newLine();

        // Detailed breakdown per retailer
        foreach ($data as $retailerData) {
            note('Missing Data: '.$retailerData['retailer_name']);
            table(
                ['Field', 'Missing Count', '% Missing'],
                [
                    [
                        'Price',
                        (string) $retailerData['missing_price'],
                        $this->percentOf($retailerData['missing_price'], $retailerData['total_listings']),
                    ],
                    [
                        'Description',
                        (string) $retailerData['missing_description'],
                        $this->percentOf($retailerData['missing_description'], $retailerData['total_listings']),
                    ],
                    [
                        'Images',
                        (string) $retailerData['missing_images'],
                        $this->percentOf($retailerData['missing_images'], $retailerData['total_listings']),
                    ],
                    [
                        'Brand',
                        (string) $retailerData['missing_brand'],
                        $this->percentOf($retailerData['missing_brand'], $retailerData['total_listings']),
                    ],
                    [
                        'Ingredients',
                        (string) $retailerData['missing_ingredients'],
                        $this->percentOf($retailerData['missing_ingredients'], $retailerData['total_listings']),
                    ],
                ]
            );
            $this->newLine();

            // Price anomalies for this retailer
            if (! empty($retailerData['price_anomalies'])) {
                warning('Price Anomalies (>50% change): '.$retailerData['retailer_name']);
                $anomalyRows = [];
                foreach ($retailerData['price_anomalies'] as $anomaly) {
                    $anomalyRows[] = [
                        (string) $anomaly['listing_id'],
                        mb_substr($anomaly['title'] ?? 'N/A', 0, 40),
                        '£'.number_format($anomaly['old_price_pence'] / 100, 2),
                        '£'.number_format($anomaly['new_price_pence'] / 100, 2),
                        $anomaly['change_percent'].'%',
                    ];
                }
                table(['ID', 'Title', 'Old Price', 'New Price', 'Change'], $anomalyRows);
                $this->newLine();
            }
        }
    }

    /**
     * Calculate percentage string.
     */
    protected function percentOf(int $part, int $total): string
    {
        if ($total === 0) {
            return '0.0%';
        }

        return round(($part / $total) * 100, 1).'%';
    }

    /**
     * Export data to CSV file.
     *
     * @param  list<array<string, mixed>>  $data
     */
    protected function exportToCsv(array $data): int
    {
        $filename = 'data-quality-report-'.now()->format('Y-m-d-His').'.csv';
        $filepath = storage_path('app/'.$filename);

        $handle = fopen($filepath, 'w');

        if ($handle === false) {
            $this->error('Could not create CSV file.');

            return self::FAILURE;
        }

        // Write header
        fputcsv($handle, [
            'Retailer',
            'Slug',
            'Total Listings',
            'Missing Price',
            'Missing Description',
            'Missing Images',
            'Missing Brand',
            'Missing Ingredients',
            'Stale Listings',
            'Price Anomalies Count',
            'Completeness Score %',
        ]);

        // Write data rows
        foreach ($data as $row) {
            fputcsv($handle, [
                $row['retailer_name'],
                $row['retailer_slug'],
                $row['total_listings'],
                $row['missing_price'],
                $row['missing_description'],
                $row['missing_images'],
                $row['missing_brand'],
                $row['missing_ingredients'],
                $row['stale_listings'],
                count($row['price_anomalies']),
                $row['completeness_score'],
            ]);
        }

        fclose($handle);

        info('CSV exported to: '.$filepath);

        return self::SUCCESS;
    }
}
