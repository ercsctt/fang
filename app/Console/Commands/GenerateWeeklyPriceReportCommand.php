<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CanonicalCategory;
use App\Services\PriceAnalytics;
use Illuminate\Console\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

class GenerateWeeklyPriceReportCommand extends Command
{
    protected $signature = 'price:weekly-report
                            {--category= : Filter by canonical category (dry_food, wet_food, treats, etc.)}
                            {--export=  : Export results to file (csv, json)}';

    protected $description = 'Generate weekly price reports for product categories';

    public function handle(PriceAnalytics $priceAnalytics): int
    {
        $categoryOption = $this->option('category');
        $exportFormat = $this->option('export');

        info('Weekly Price Report - '.now()->format('Y-m-d H:i:s'));
        $this->newLine();

        if ($categoryOption !== null) {
            $category = CanonicalCategory::tryFrom($categoryOption);

            if ($category === null) {
                $this->error("Invalid category: {$categoryOption}");
                $this->line('Valid categories: '.implode(', ', array_map(
                    fn ($c) => $c->value,
                    CanonicalCategory::cases()
                )));

                return self::FAILURE;
            }

            $reports = [$category->value => $priceAnalytics->generateCategoryWeeklyReport($category)];
        } else {
            $reports = $priceAnalytics->generateAllCategoriesWeeklyReport();
        }

        if ($exportFormat !== null) {
            return $this->exportReport($reports, $exportFormat);
        }

        $this->displayReport($reports);

        return self::SUCCESS;
    }

    /**
     * Display the report using Laravel Prompts.
     *
     * @param  array<string, \App\DTOs\CategoryPriceReport>  $reports
     */
    protected function displayReport(array $reports): void
    {
        note('Category Summary');
        $summaryRows = [];

        foreach ($reports as $report) {
            $summaryRows[] = [
                $report->category->label(),
                (string) $report->totalListings,
                '£'.number_format($report->averagePricePence / 100, 2),
                '£'.number_format($report->minPricePence / 100, 2),
                '£'.number_format($report->maxPricePence / 100, 2),
                $report->averagePriceChange >= 0
                    ? '+'.$report->averagePriceChange.'%'
                    : $report->averagePriceChange.'%',
                $report->listingsOnSale.' ('.$report->salePercentage.'%)',
            ];
        }

        table(
            ['Category', 'Listings', 'Avg Price', 'Min Price', 'Max Price', 'Price Change', 'On Sale'],
            $summaryRows
        );
        $this->newLine();

        foreach ($reports as $report) {
            if (! empty($report->topPriceDrops)) {
                note('Top Price Drops: '.$report->category->label());
                $dropRows = [];

                foreach ($report->topPriceDrops as $drop) {
                    $dropRows[] = [
                        (string) $drop['product_listing_id'],
                        mb_substr($drop['title'], 0, 50),
                        '£'.number_format($drop['previous_price_pence'] / 100, 2),
                        '£'.number_format($drop['current_price_pence'] / 100, 2),
                        $drop['change_percentage'].'%',
                    ];
                }

                table(['ID', 'Title', 'Previous', 'Current', 'Change'], $dropRows);
                $this->newLine();
            }

            if (! empty($report->topPriceIncreases)) {
                note('Top Price Increases: '.$report->category->label());
                $increaseRows = [];

                foreach ($report->topPriceIncreases as $increase) {
                    $increaseRows[] = [
                        (string) $increase['product_listing_id'],
                        mb_substr($increase['title'], 0, 50),
                        '£'.number_format($increase['previous_price_pence'] / 100, 2),
                        '£'.number_format($increase['current_price_pence'] / 100, 2),
                        '+'.$increase['change_percentage'].'%',
                    ];
                }

                table(['ID', 'Title', 'Previous', 'Current', 'Change'], $increaseRows);
                $this->newLine();
            }
        }
    }

    /**
     * Export report to file.
     *
     * @param  array<string, \App\DTOs\CategoryPriceReport>  $reports
     */
    protected function exportReport(array $reports, string $format): int
    {
        $filename = 'weekly-price-report-'.now()->format('Y-m-d-His');

        if ($format === 'json') {
            return $this->exportToJson($reports, $filename);
        }

        if ($format === 'csv') {
            return $this->exportToCsv($reports, $filename);
        }

        $this->error("Unsupported export format: {$format}. Use 'csv' or 'json'.");

        return self::FAILURE;
    }

    /**
     * Export to JSON file.
     *
     * @param  array<string, \App\DTOs\CategoryPriceReport>  $reports
     */
    protected function exportToJson(array $reports, string $filename): int
    {
        $filepath = storage_path('app/'.$filename.'.json');

        $data = [];
        foreach ($reports as $report) {
            $data[$report->category->value] = $report->toArray();
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $this->error('Failed to encode report to JSON.');

            return self::FAILURE;
        }

        file_put_contents($filepath, $json);
        info('JSON exported to: '.$filepath);

        return self::SUCCESS;
    }

    /**
     * Export to CSV file.
     *
     * @param  array<string, \App\DTOs\CategoryPriceReport>  $reports
     */
    protected function exportToCsv(array $reports, string $filename): int
    {
        $filepath = storage_path('app/'.$filename.'.csv');

        $handle = fopen($filepath, 'w');

        if ($handle === false) {
            $this->error('Could not create CSV file.');

            return self::FAILURE;
        }

        fputcsv($handle, [
            'Category',
            'Period Start',
            'Period End',
            'Total Listings',
            'Average Price (pence)',
            'Min Price (pence)',
            'Max Price (pence)',
            'Average Price Change %',
            'Listings On Sale',
            'Sale Percentage %',
        ]);

        foreach ($reports as $report) {
            fputcsv($handle, [
                $report->category->label(),
                $report->periodStart->format('Y-m-d'),
                $report->periodEnd->format('Y-m-d'),
                $report->totalListings,
                $report->averagePricePence,
                $report->minPricePence,
                $report->maxPricePence,
                $report->averagePriceChange,
                $report->listingsOnSale,
                $report->salePercentage,
            ]);
        }

        fclose($handle);
        info('CSV exported to: '.$filepath);

        return self::SUCCESS;
    }
}
