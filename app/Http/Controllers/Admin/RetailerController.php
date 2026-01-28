<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Crawler\Contracts\HttpAdapterInterface;
use App\Enums\RetailerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRetailerRequest;
use App\Http\Requests\Admin\UpdateRetailerRequest;
use App\Models\CrawlStatistic;
use App\Models\Retailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RetailerController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Retailer::query()->withCount('productListings');

        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        if ($request->filled('search')) {
            $search = strtolower($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(base_url) LIKE ?', ["%{$search}%"]);
            });
        }

        $sortField = $request->input('sort', 'name');
        $sortDirection = $request->input('dir', 'asc');

        $allowedSortFields = [
            'name',
            'slug',
            'status',
            'last_crawled_at',
            'consecutive_failures',
            'product_listings_count',
        ];

        if (in_array($sortField, $allowedSortFields, true)) {
            $query->orderBy($sortField, $sortDirection === 'desc' ? 'desc' : 'asc');
        } else {
            $query->orderBy('name', 'asc');
        }

        $retailers = $query->get()->map(fn (Retailer $retailer): array => $this->formatRetailer($retailer));

        $statusCounts = $this->getStatusCounts();
        $summaryStats = $this->getSummaryStats();

        return Inertia::render('Admin/Retailers/Index', [
            'retailers' => $retailers,
            'statusCounts' => $statusCounts,
            'summaryStats' => $summaryStats,
            'filters' => [
                'status' => $request->input('status', 'all'),
                'search' => $request->input('search', ''),
                'sort' => $sortField,
                'dir' => $sortDirection,
            ],
            'statuses' => $this->getAvailableStatuses(),
        ]);
    }

    /**
     * Format a retailer for the frontend.
     *
     * @return array<string, mixed>
     */
    private function formatRetailer(Retailer $retailer): array
    {
        return [
            'id' => $retailer->id,
            'name' => $retailer->name,
            'slug' => $retailer->slug,
            'base_url' => $retailer->base_url,
            'status' => $retailer->status->value,
            'status_label' => $retailer->status->label(),
            'status_color' => $retailer->status->color(),
            'status_description' => $retailer->status->description(),
            'status_badge_classes' => $retailer->status->badgeClasses(),
            'status_icon' => $retailer->status->icon(),
            'consecutive_failures' => $retailer->consecutive_failures,
            'last_failure_at' => $retailer->last_failure_at?->toIso8601String(),
            'paused_until' => $retailer->paused_until?->toIso8601String(),
            'last_crawled_at' => $retailer->last_crawled_at?->toIso8601String(),
            'is_paused' => $retailer->isPaused(),
            'is_available_for_crawling' => $retailer->isAvailableForCrawling(),
            'product_listings_count' => $retailer->product_listings_count ?? 0,
            'can_pause' => $retailer->status !== RetailerStatus::Paused && $retailer->status->canTransitionTo(RetailerStatus::Paused),
            'can_resume' => $retailer->status === RetailerStatus::Paused,
            'can_disable' => $retailer->status !== RetailerStatus::Disabled && $retailer->status->canTransitionTo(RetailerStatus::Disabled),
            'can_enable' => $retailer->status === RetailerStatus::Disabled || $retailer->status === RetailerStatus::Failed,
        ];
    }

    /**
     * Get counts for each status.
     *
     * @return array<string, int>
     */
    private function getStatusCounts(): array
    {
        $counts = Retailer::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $result = ['all' => 0];
        foreach (RetailerStatus::cases() as $status) {
            $result[$status->value] = $counts[$status->value] ?? 0;
            $result['all'] += $result[$status->value];
        }

        return $result;
    }

    /**
     * Get summary statistics.
     *
     * @return array<string, mixed>
     */
    private function getSummaryStats(): array
    {
        $total = Retailer::count();
        $crawlable = Retailer::query()
            ->whereIn('status', RetailerStatus::crawlableStatuses())
            ->count();
        $withProblems = Retailer::query()
            ->whereIn('status', RetailerStatus::problemStatuses())
            ->count();
        $recentlyCrawled = Retailer::query()
            ->where('last_crawled_at', '>=', now()->subHours(24))
            ->count();
        $totalProducts = Retailer::query()
            ->withCount('productListings')
            ->get()
            ->sum('product_listings_count');

        return [
            'total' => $total,
            'crawlable' => $crawlable,
            'with_problems' => $withProblems,
            'recently_crawled' => $recentlyCrawled,
            'total_products' => $totalProducts,
        ];
    }

    /**
     * Get available statuses for filtering.
     *
     * @return array<int, array{value: string, label: string, color: string}>
     */
    private function getAvailableStatuses(): array
    {
        return array_map(
            fn (RetailerStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
                'color' => $status->color(),
            ],
            RetailerStatus::cases()
        );
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Retailers/Create', [
            'crawlerClasses' => $this->getAvailableCrawlerClasses(),
            'statuses' => $this->getAvailableStatuses(),
            'defaultStatus' => RetailerStatus::default()->value,
        ]);
    }

    public function store(StoreRetailerRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $slug = $validated['slug'] ?? Str::slug($validated['name']);

        $retailer = Retailer::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'base_url' => $validated['base_url'],
            'crawler_class' => $validated['crawler_class'],
            'rate_limit_ms' => $validated['rate_limit_ms'],
            'status' => RetailerStatus::from($validated['status']),
            'consecutive_failures' => 0,
        ]);

        return redirect()
            ->route('admin.retailers.edit', $retailer)
            ->with('success', 'Retailer created successfully.');
    }

    public function edit(Retailer $retailer): Response
    {
        $retailer->loadCount('productListings');
        $statistics = $this->getRetailerStatistics($retailer);
        $failureHistory = $this->getFailureHistory($retailer);

        return Inertia::render('Admin/Retailers/Edit', [
            'retailer' => $this->formatRetailerForEdit($retailer),
            'crawlerClasses' => $this->getAvailableCrawlerClasses(),
            'statuses' => $this->getAvailableStatuses(),
            'statistics' => $statistics,
            'failureHistory' => $failureHistory,
        ]);
    }

    public function update(UpdateRetailerRequest $request, Retailer $retailer): RedirectResponse
    {
        $validated = $request->validated();

        $slug = $validated['slug'] ?? Str::slug($validated['name']);

        $retailer->update([
            'name' => $validated['name'],
            'slug' => $slug,
            'base_url' => $validated['base_url'],
            'crawler_class' => $validated['crawler_class'],
            'rate_limit_ms' => $validated['rate_limit_ms'],
            'status' => RetailerStatus::from($validated['status']),
        ]);

        return redirect()
            ->route('admin.retailers.edit', $retailer)
            ->with('success', 'Retailer updated successfully.');
    }

    public function testConnection(Retailer $retailer): JsonResponse
    {
        $crawlerClass = $retailer->crawler_class;

        if (! $crawlerClass || ! class_exists($crawlerClass)) {
            return response()->json([
                'success' => false,
                'message' => 'Crawler class not found or not configured.',
            ], 400);
        }

        try {
            $httpAdapter = app(HttpAdapterInterface::class);
            $crawler = new $crawlerClass($httpAdapter);

            $startingUrls = $crawler->getStartingUrls();
            if (empty($startingUrls)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Crawler has no starting URLs configured.',
                ]);
            }

            $testUrl = $startingUrls[0];
            $html = $httpAdapter->fetchHtml($testUrl, []);
            $statusCode = $httpAdapter->getLastStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return response()->json([
                    'success' => true,
                    'message' => 'Connection successful.',
                    'details' => [
                        'status_code' => $statusCode,
                        'html_length' => strlen($html),
                        'test_url' => $testUrl,
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => "Connection failed with status code {$statusCode}.",
                'details' => [
                    'status_code' => $statusCode,
                    'test_url' => $testUrl,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection test failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Format a retailer for the edit form.
     *
     * @return array<string, mixed>
     */
    private function formatRetailerForEdit(Retailer $retailer): array
    {
        return [
            'id' => $retailer->id,
            'name' => $retailer->name,
            'slug' => $retailer->slug,
            'base_url' => $retailer->base_url,
            'crawler_class' => $retailer->crawler_class,
            'rate_limit_ms' => $retailer->rate_limit_ms,
            'status' => $retailer->status->value,
            'status_label' => $retailer->status->label(),
            'status_color' => $retailer->status->color(),
            'status_description' => $retailer->status->description(),
            'status_badge_classes' => $retailer->status->badgeClasses(),
            'consecutive_failures' => $retailer->consecutive_failures,
            'last_failure_at' => $retailer->last_failure_at?->toIso8601String(),
            'paused_until' => $retailer->paused_until?->toIso8601String(),
            'last_crawled_at' => $retailer->last_crawled_at?->toIso8601String(),
            'is_paused' => $retailer->isPaused(),
            'is_available_for_crawling' => $retailer->isAvailableForCrawling(),
            'product_listings_count' => $retailer->product_listings_count ?? 0,
            'created_at' => $retailer->created_at?->toIso8601String(),
            'updated_at' => $retailer->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Get available crawler classes from the filesystem.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function getAvailableCrawlerClasses(): array
    {
        $crawlerPath = app_path('Crawler/Scrapers');
        $crawlers = [];

        if (! File::isDirectory($crawlerPath)) {
            return $crawlers;
        }

        $files = File::files($crawlerPath);

        foreach ($files as $file) {
            $className = $file->getFilenameWithoutExtension();

            if ($className === 'BaseCrawler') {
                continue;
            }

            if (! str_ends_with($className, 'Crawler')) {
                continue;
            }

            $fullClass = 'App\\Crawler\\Scrapers\\'.$className;

            if (! class_exists($fullClass)) {
                continue;
            }

            $label = str_replace('Crawler', '', $className);
            $label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $label) ?? $label;

            $crawlers[] = [
                'value' => $fullClass,
                'label' => $label,
            ];
        }

        usort($crawlers, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $crawlers;
    }

    /**
     * Get crawl statistics for a retailer.
     *
     * @return array<string, mixed>
     */
    private function getRetailerStatistics(Retailer $retailer): array
    {
        $lastSevenDays = CrawlStatistic::query()
            ->where('retailer_id', $retailer->id)
            ->where('date', '>=', now()->subDays(7))
            ->orderBy('date', 'desc')
            ->get();

        $totals = [
            'crawls_started' => $lastSevenDays->sum('crawls_started'),
            'crawls_completed' => $lastSevenDays->sum('crawls_completed'),
            'crawls_failed' => $lastSevenDays->sum('crawls_failed'),
            'listings_discovered' => $lastSevenDays->sum('listings_discovered'),
            'details_extracted' => $lastSevenDays->sum('details_extracted'),
        ];

        $successRate = ($totals['crawls_completed'] + $totals['crawls_failed']) > 0
            ? round(($totals['crawls_completed'] / ($totals['crawls_completed'] + $totals['crawls_failed'])) * 100, 2)
            : null;

        return [
            'product_count' => $retailer->product_listings_count ?? 0,
            'last_crawled_at' => $retailer->last_crawled_at?->toIso8601String(),
            'last_seven_days' => $totals,
            'success_rate' => $successRate,
            'daily_stats' => $lastSevenDays->map(fn (CrawlStatistic $stat): array => [
                'date' => $stat->date->toDateString(),
                'crawls_started' => $stat->crawls_started,
                'crawls_completed' => $stat->crawls_completed,
                'crawls_failed' => $stat->crawls_failed,
                'listings_discovered' => $stat->listings_discovered,
                'details_extracted' => $stat->details_extracted,
                'success_rate' => $stat->success_rate,
            ])->toArray(),
        ];
    }

    /**
     * Get failure history for a retailer.
     *
     * @return array<string, mixed>
     */
    private function getFailureHistory(Retailer $retailer): array
    {
        $recentFailures = CrawlStatistic::query()
            ->where('retailer_id', $retailer->id)
            ->where('crawls_failed', '>', 0)
            ->orderBy('date', 'desc')
            ->limit(10)
            ->get();

        return [
            'consecutive_failures' => $retailer->consecutive_failures,
            'last_failure_at' => $retailer->last_failure_at?->toIso8601String(),
            'recent_failure_dates' => $recentFailures->pluck('date')->map(fn ($date) => $date->toDateString())->toArray(),
            'total_failures_last_30_days' => CrawlStatistic::query()
                ->where('retailer_id', $retailer->id)
                ->where('date', '>=', now()->subDays(30))
                ->sum('crawls_failed'),
        ];
    }
}
