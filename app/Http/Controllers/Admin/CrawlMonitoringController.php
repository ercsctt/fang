<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\MatchType;
use App\Http\Controllers\Controller;
use App\Models\CrawlStatistic;
use App\Models\ProductListing;
use App\Models\ProductListingMatch;
use App\Models\Retailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CrawlMonitoringController extends Controller
{
    public function index(Request $request): Response
    {
        $dateRange = $request->input('range', '7');
        $startDate = now()->subDays((int) $dateRange);

        $retailers = Retailer::query()
            ->withCount('productListings')
            ->orderBy('name')
            ->get()
            ->map(fn (Retailer $retailer) => [
                'id' => $retailer->id,
                'name' => $retailer->name,
                'slug' => $retailer->slug,
                'status' => $retailer->status->value,
                'status_label' => $retailer->status->label(),
                'status_color' => $retailer->status->color(),
                'consecutive_failures' => $retailer->consecutive_failures,
                'last_failure_at' => $retailer->last_failure_at?->toIso8601String(),
                'paused_until' => $retailer->paused_until?->toIso8601String(),
                'last_crawled_at' => $retailer->last_crawled_at?->toIso8601String(),
                'is_paused' => $retailer->isPaused(),
                'is_available_for_crawling' => $retailer->isAvailableForCrawling(),
                'product_listings_count' => $retailer->product_listings_count,
            ]);

        $statistics = CrawlStatistic::query()
            ->with('retailer:id,name,slug')
            ->where('date', '>=', $startDate)
            ->orderBy('date', 'desc')
            ->get()
            ->map(fn (CrawlStatistic $stat) => [
                'id' => $stat->id,
                'retailer_id' => $stat->retailer_id,
                'retailer_name' => $stat->retailer?->name,
                'retailer_slug' => $stat->retailer?->slug,
                'date' => $stat->date->toDateString(),
                'crawls_started' => $stat->crawls_started,
                'crawls_completed' => $stat->crawls_completed,
                'crawls_failed' => $stat->crawls_failed,
                'listings_discovered' => $stat->listings_discovered,
                'details_extracted' => $stat->details_extracted,
                'average_duration_ms' => $stat->average_duration_ms,
                'success_rate' => $stat->success_rate,
            ]);

        $todayStats = $this->getTodayStats();
        $matchingStats = $this->getMatchingStats();
        $dataFreshnessStats = $this->getDataFreshnessStats();
        $failedJobs = $this->getFailedJobs();
        $chartData = $this->getChartData((int) $dateRange);

        return Inertia::render('Admin/CrawlMonitoring/Index', [
            'retailers' => $retailers,
            'statistics' => $statistics,
            'todayStats' => $todayStats,
            'matchingStats' => $matchingStats,
            'dataFreshnessStats' => $dataFreshnessStats,
            'failedJobs' => $failedJobs,
            'chartData' => $chartData,
            'filters' => [
                'range' => $dateRange,
            ],
        ]);
    }

    public function retryJob(Request $request, int $jobId): JsonResponse
    {
        $job = DB::table('failed_jobs')->where('id', $jobId)->first();

        if (! $job) {
            return response()->json(['message' => 'Job not found'], 404);
        }

        DB::table('jobs')->insert([
            'queue' => $job->queue,
            'payload' => $job->payload,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        DB::table('failed_jobs')->where('id', $jobId)->delete();

        return response()->json(['message' => 'Job queued for retry']);
    }

    public function deleteJob(Request $request, int $jobId): JsonResponse
    {
        $deleted = DB::table('failed_jobs')->where('id', $jobId)->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Job not found'], 404);
        }

        return response()->json(['message' => 'Job deleted']);
    }

    public function retryAllJobs(): JsonResponse
    {
        $jobs = DB::table('failed_jobs')->get();

        foreach ($jobs as $job) {
            DB::table('jobs')->insert([
                'queue' => $job->queue,
                'payload' => $job->payload,
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }

        DB::table('failed_jobs')->truncate();

        return response()->json(['message' => 'All jobs queued for retry', 'count' => $jobs->count()]);
    }

    /**
     * @return array{crawls_started: int, crawls_completed: int, crawls_failed: int, listings_discovered: int, details_extracted: int, success_rate: float|null}
     */
    private function getTodayStats(): array
    {
        $stats = CrawlStatistic::query()
            ->where('date', today())
            ->selectRaw('
                COALESCE(SUM(crawls_started), 0) as crawls_started,
                COALESCE(SUM(crawls_completed), 0) as crawls_completed,
                COALESCE(SUM(crawls_failed), 0) as crawls_failed,
                COALESCE(SUM(listings_discovered), 0) as listings_discovered,
                COALESCE(SUM(details_extracted), 0) as details_extracted
            ')
            ->first();

        $total = $stats->crawls_completed + $stats->crawls_failed;
        $successRate = $total > 0
            ? round(($stats->crawls_completed / $total) * 100, 2)
            : null;

        return [
            'crawls_started' => (int) $stats->crawls_started,
            'crawls_completed' => (int) $stats->crawls_completed,
            'crawls_failed' => (int) $stats->crawls_failed,
            'listings_discovered' => (int) $stats->listings_discovered,
            'details_extracted' => (int) $stats->details_extracted,
            'success_rate' => $successRate,
        ];
    }

    /**
     * @return array{exact: int, fuzzy: int, barcode: int, manual: int, unmatched: int, total_listings: int}
     */
    private function getMatchingStats(): array
    {
        $matchCounts = ProductListingMatch::query()
            ->select('match_type')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('match_type')
            ->pluck('count', 'match_type')
            ->toArray();

        $totalListings = ProductListing::count();
        $matchedListings = ProductListingMatch::query()
            ->distinct('product_listing_id')
            ->count('product_listing_id');

        return [
            'exact' => $matchCounts[MatchType::Exact->value] ?? 0,
            'fuzzy' => $matchCounts[MatchType::Fuzzy->value] ?? 0,
            'barcode' => $matchCounts[MatchType::Barcode->value] ?? 0,
            'manual' => $matchCounts[MatchType::Manual->value] ?? 0,
            'unmatched' => max(0, $totalListings - $matchedListings),
            'total_listings' => $totalListings,
        ];
    }

    /**
     * @return array{fresh: int, stale_24h: int, stale_48h: int, stale_week: int, never_scraped: int, total: int}
     */
    private function getDataFreshnessStats(): array
    {
        $total = ProductListing::count();

        $fresh = ProductListing::query()
            ->where('last_scraped_at', '>=', now()->subHours(24))
            ->count();

        $stale24h = ProductListing::query()
            ->where('last_scraped_at', '<', now()->subHours(24))
            ->where('last_scraped_at', '>=', now()->subHours(48))
            ->count();

        $stale48h = ProductListing::query()
            ->where('last_scraped_at', '<', now()->subHours(48))
            ->where('last_scraped_at', '>=', now()->subDays(7))
            ->count();

        $staleWeek = ProductListing::query()
            ->where('last_scraped_at', '<', now()->subDays(7))
            ->count();

        $neverScraped = ProductListing::query()
            ->whereNull('last_scraped_at')
            ->count();

        return [
            'fresh' => $fresh,
            'stale_24h' => $stale24h,
            'stale_48h' => $stale48h,
            'stale_week' => $staleWeek,
            'never_scraped' => $neverScraped,
            'total' => $total,
        ];
    }

    /**
     * @return array<int, array{id: int, uuid: string, queue: string, payload_summary: string, exception_summary: string, failed_at: string}>
     */
    private function getFailedJobs(): array
    {
        $jobs = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit(50)
            ->get();

        return $jobs->map(function ($job) {
            $payload = json_decode($job->payload, true);
            $commandName = $payload['displayName'] ?? 'Unknown';

            $exceptionLines = explode("\n", $job->exception);
            $exceptionSummary = $exceptionLines[0] ?? 'Unknown error';

            return [
                'id' => $job->id,
                'uuid' => $job->uuid,
                'queue' => $job->queue,
                'payload_summary' => $commandName,
                'exception_summary' => mb_substr($exceptionSummary, 0, 200),
                'failed_at' => $job->failed_at,
            ];
        })->toArray();
    }

    /**
     * @return array{labels: list<string>, datasets: array{crawls: list<int>, listings: list<int>, failures: list<int>}}
     */
    private function getChartData(int $days): array
    {
        $startDate = now()->subDays($days);

        $stats = CrawlStatistic::query()
            ->where('date', '>=', $startDate)
            ->selectRaw('
                date,
                SUM(crawls_completed) as crawls_completed,
                SUM(crawls_failed) as crawls_failed,
                SUM(listings_discovered) as listings_discovered
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($stat) => $stat->date->toDateString());

        $labels = [];
        $crawls = [];
        $listings = [];
        $failures = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $labels[] = now()->subDays($i)->format('M j');

            $dayStat = $stats[$date] ?? null;
            $crawls[] = $dayStat ? (int) $dayStat->crawls_completed : 0;
            $listings[] = $dayStat ? (int) $dayStat->listings_discovered : 0;
            $failures[] = $dayStat ? (int) $dayStat->crawls_failed : 0;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                'crawls' => $crawls,
                'listings' => $listings,
                'failures' => $failures,
            ],
        ];
    }
}
