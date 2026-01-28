/**
 * Admin Dashboard TypeScript Types
 *
 * Shared type definitions for admin pages that match backend controller responses.
 * These interfaces are aligned with the PHP controllers in App\Http\Controllers\Admin\
 */

// =============================================================================
// Retailer Status Types
// =============================================================================

/**
 * Valid retailer status values matching App\Enums\RetailerStatus
 */
export type RetailerStatusValue =
    | 'active'
    | 'paused'
    | 'disabled'
    | 'degraded'
    | 'failed';

/**
 * Status option for dropdown filters
 * Returned by RetailerController::getAvailableStatuses()
 */
export interface StatusOption {
    value: string;
    label: string;
    color: string;
}

// =============================================================================
// Retailer Types
// =============================================================================

/**
 * Retailer health data for Crawl Monitoring dashboard
 * Returned by CrawlMonitoringController::index()
 */
export interface RetailerHealth {
    id: number;
    name: string;
    slug: string;
    status: RetailerStatusValue;
    status_label: string;
    status_color: string;
    consecutive_failures: number;
    last_failure_at: string | null;
    paused_until: string | null;
    last_crawled_at: string | null;
    is_paused: boolean;
    is_available_for_crawling: boolean;
    product_listings_count: number;
}

/**
 * Full retailer data for Retailers Index page
 * Returned by RetailerController::formatRetailer()
 */
export interface RetailerData {
    id: number;
    name: string;
    slug: string;
    base_url: string;
    status: string;
    status_label: string;
    status_color: string;
    status_description: string;
    status_badge_classes: string;
    status_icon: string;
    consecutive_failures: number;
    last_failure_at: string | null;
    paused_until: string | null;
    last_crawled_at: string | null;
    is_paused: boolean;
    is_available_for_crawling: boolean;
    product_listings_count: number;
    can_pause: boolean;
    can_resume: boolean;
    can_disable: boolean;
    can_enable: boolean;
}

/**
 * Retailer data for Edit page
 * Returned by RetailerController::formatRetailerForEdit()
 */
export interface RetailerEditData {
    id: number;
    name: string;
    slug: string;
    base_url: string;
    crawler_class: string | null;
    rate_limit_ms: number;
    status: string;
    status_label: string;
    status_color: string;
    status_description: string;
    status_badge_classes: string;
    consecutive_failures: number;
    last_failure_at: string | null;
    paused_until: string | null;
    last_crawled_at: string | null;
    is_paused: boolean;
    is_available_for_crawling: boolean;
    product_listings_count: number;
    created_at: string | null;
    updated_at: string | null;
}

/**
 * Crawler class option for forms
 * Returned by RetailerController::getAvailableCrawlerClasses()
 */
export interface CrawlerClass {
    value: string;
    label: string;
}

/**
 * Retailer status counts for filtering
 * Returned by RetailerController::getStatusCounts()
 */
export interface StatusCounts {
    all: number;
    active: number;
    paused: number;
    disabled: number;
    degraded: number;
    failed: number;
    [key: string]: number;
}

/**
 * Summary statistics for retailers
 * Returned by RetailerController::getSummaryStats()
 */
export interface RetailerSummaryStats {
    total: number;
    crawlable: number;
    with_problems: number;
    recently_crawled: number;
    total_products: number;
}

/**
 * Retailer filters for index page
 */
export interface RetailerFilters {
    status: string;
    search: string;
    sort: string;
    dir: string;
}

// =============================================================================
// Crawl Statistics Types
// =============================================================================

/**
 * Daily crawl statistic record
 * Returned by CrawlMonitoringController::index()
 */
export interface CrawlStatistic {
    id: number;
    retailer_id: number;
    retailer_name: string | null;
    retailer_slug: string | null;
    date: string;
    crawls_started: number;
    crawls_completed: number;
    crawls_failed: number;
    listings_discovered: number;
    details_extracted: number;
    average_duration_ms: number | null;
    success_rate: number | null;
}

/**
 * Today's aggregated stats
 * Returned by CrawlMonitoringController::getTodayStats()
 */
export interface TodayStats {
    crawls_started: number;
    crawls_completed: number;
    crawls_failed: number;
    listings_discovered: number;
    details_extracted: number;
    success_rate: number | null;
}

/**
 * Matching statistics by type
 * Returned by CrawlMonitoringController::getMatchingStats()
 */
export interface MatchingStats {
    exact: number;
    fuzzy: number;
    barcode: number;
    manual: number;
    unmatched: number;
    total_listings: number;
}

/**
 * Data freshness statistics
 * Returned by CrawlMonitoringController::getDataFreshnessStats()
 */
export interface DataFreshnessStats {
    fresh: number;
    stale_24h: number;
    stale_48h: number;
    stale_week: number;
    never_scraped: number;
    total: number;
}

/**
 * Failed job record
 * Returned by CrawlMonitoringController::getFailedJobs()
 */
export interface FailedJob {
    id: number;
    uuid: string;
    queue: string;
    payload_summary: string;
    exception_summary: string;
    failed_at: string;
}

/**
 * Chart data for crawl activity visualization
 * Returned by CrawlMonitoringController::getChartData()
 */
export interface ChartData {
    labels: string[];
    datasets: {
        crawls: number[];
        listings: number[];
        failures: number[];
    };
}

/**
 * Daily stats for retailer edit page
 * Returned by RetailerController::getRetailerStatistics()
 */
export interface DailyStat {
    date: string;
    crawls_started: number;
    crawls_completed: number;
    crawls_failed: number;
    listings_discovered: number;
    details_extracted: number;
    success_rate: number | null;
}

/**
 * Retailer statistics for edit page
 * Returned by RetailerController::getRetailerStatistics()
 */
export interface RetailerStatistics {
    product_count: number;
    last_crawled_at: string | null;
    last_seven_days: {
        crawls_started: number;
        crawls_completed: number;
        crawls_failed: number;
        listings_discovered: number;
        details_extracted: number;
    };
    success_rate: number | null;
    daily_stats: DailyStat[];
}

/**
 * Failure history for retailer edit page
 * Returned by RetailerController::getFailureHistory()
 */
export interface FailureHistory {
    consecutive_failures: number;
    last_failure_at: string | null;
    recent_failure_dates: string[];
    total_failures_last_30_days: number;
}

// =============================================================================
// Product Verification Types
// =============================================================================

/**
 * Simplified retailer for product verification
 */
export interface VerificationRetailer {
    id: number;
    name: string;
    slug: string;
}

/**
 * Product for verification display
 * Returned in ProductListingMatch relationships
 */
export interface VerificationProduct {
    id: number;
    name: string;
    slug: string;
    brand: string | null;
    primary_image: string | null;
    weight_grams: number | null;
    quantity: number | null;
}

/**
 * Extended product for verification detail page
 */
export interface VerificationProductDetail extends VerificationProduct {
    description: string | null;
    category: string | null;
    subcategory: string | null;
}

/**
 * Product listing for verification display
 */
export interface VerificationProductListing {
    id: number;
    retailer_id: number;
    title: string;
    brand: string | null;
    url: string;
    price_pence: number | null;
    images: string[] | null;
    weight_grams: number | null;
    quantity: number | null;
    retailer: VerificationRetailer;
}

/**
 * Extended product listing for verification detail page
 */
export interface VerificationProductListingDetail
    extends VerificationProductListing {
    description: string | null;
    category: string | null;
    ingredients: string | null;
}

/**
 * User who verified a match
 */
export interface Verifier {
    id: number;
    name: string;
}

/**
 * Product listing match for verification
 * Returned by ProductVerificationController::index()
 */
export interface Match {
    id: number;
    product_id: number;
    product_listing_id: number;
    confidence_score: number;
    match_type: string;
    matched_at: string;
    verified_at: string | null;
    status: string;
    rejection_reason: string | null;
    product: VerificationProduct;
    product_listing: VerificationProductListing;
    verifier: Verifier | null;
}

/**
 * Match for detail page with extended product info
 */
export interface MatchDetail {
    id: number;
    product_id: number;
    product_listing_id: number;
    confidence_score: number;
    match_type: string;
    matched_at: string;
    verified_at: string | null;
    status: string;
    rejection_reason: string | null;
    product: VerificationProductDetail;
    product_listing: VerificationProductListingDetail;
    verifier: Verifier | null;
}

/**
 * Other match for same product
 */
export interface OtherMatch {
    id: number;
    product_listing: {
        id: number;
        retailer_id: number;
        title: string;
        url: string;
        price_pence: number | null;
        retailer: {
            id: number;
            name: string;
        };
    };
}

/**
 * Suggested product for rematch
 */
export interface SuggestedProduct {
    id: number;
    name: string;
    slug: string;
    brand: string | null;
    primary_image: string | null;
}

/**
 * Paginated matches response
 */
export interface PaginatedMatches {
    data: Match[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

/**
 * Verification statistics
 * Returned by ProductVerificationController::getVerificationStats()
 */
export interface VerificationStats {
    pending: number;
    approved: number;
    rejected: number;
    total: number;
    high_confidence_pending: number;
}

/**
 * Verification page filters
 */
export interface VerificationFilters {
    status: string;
    sort: string;
    direction: string;
}
