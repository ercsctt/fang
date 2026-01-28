# Epic: Frontend Admin Dashboard Updates (e-4ddbac)

## Plan

Update the admin dashboard frontend to properly reflect backend changes, add missing navigation links, fix data field mismatches, and add browser tests for admin functionality.

<!-- Add implementation plan here -->

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->

### Task f-a37430: Add missing admin navigation links
- Added Retailers Management (`/admin/retailers`) and Product Verification (`/admin/product-verification`) links to AppSidebar.vue
- Used Wayfinder-generated routes (`admin.retailers.index()` and `admin.productVerification.index()`) instead of hardcoded strings
- Also updated existing Crawl Monitoring and Scraper Tester to use Wayfinder routes for consistency
- Added Store and CheckCircle icons from lucide-vue-next for the new nav items
- Pattern: Import admin routes from `@/routes/admin` and use `admin.{controller}.{method}()` syntax

### Task f-1fc9db: RetailerHealthTable data field mismatch fix
- Updated `RetailerHealth` interface to match backend response:
  - `status` (enum: 'active' | 'paused' | 'disabled' | 'degraded' | 'failed') instead of `health_status`
  - `status_label` instead of `health_status_label`
  - `status_color` instead of `health_status_color`
  - Removed `is_active` as it's not provided by backend
- Updated sorting priority: failed (0) > disabled (1) > degraded (2) > paused (3) > active (4)
- Added `MinusCircle` icon for disabled status
- Status badge colors now match the backend `RetailerStatus` enum colors exactly:
  - active: green
  - paused: yellow
  - disabled: gray
  - degraded: orange
  - failed: red

### Task f-8f029d: Update Crawl Monitoring KPI cards for new status enum
- Updated `CrawlMonitoring/Index.vue` to use new status enum for KPI calculations:
  - `RetailerHealth` interface updated to use `status` instead of `health_status`
  - Added `pausedCount` computed property to count retailers with status 'paused'
  - Updated `healthyCount` to filter for status === 'active'
  - Updated `unhealthyCount` to filter for status === 'disabled' OR 'failed'
  - `degradedCount` unchanged (status === 'degraded')
- Added `PauseCircle` icon import from lucide-vue-next
- Added new paused badge to KPI card (blue color scheme) between healthy and degraded
- Added `flex-wrap` to badge container to handle multiple badges gracefully
- Status mapping: active->healthy, paused->paused (separate), degraded->degraded, disabled/failed->unhealthy

### Task f-fd1ede: Fix sidebar footer navigation links
- Removed the `footerNavItems` array which contained links to Laravel starter kit defaults
- Removed the `NavFooter` component import and usage from the sidebar
- Removed unused icon imports (`BookOpen`, `Folder`) from lucide-vue-next
- Decision: Opted to remove the footer nav entirely rather than replace with project docs, as this is an internal admin tool
- The `NavUser` component remains in the sidebar footer for user account access

### Task f-8061bd: Add browser tests for Retailers admin
- Created `tests/Browser/Admin/RetailersTest.php` with Pest v4 browser tests
- Tests cover:
  1. Index page loads with retailer list
  2. Status filter dropdown functionality
  3. Search functionality
  4. Create retailer page loads with form
  5. Retailer creation flow
  6. Edit retailer page loads with pre-populated data
  7. Retailer update flow
  8. Pause/Resume status actions
  9. Disable/Enable status actions
  10. Test connection button behavior
- Pattern: Created `loginAndVisit(User $user, string $url)` helper function to authenticate via browser login before visiting protected pages
- Note: Browser tests require Playwright with system dependencies. Tests use real browser interaction via `visit()`, `click()`, `type()`, `waitFor()`, etc.
- Updated `tests/Pest.php` to include Browser directory in test configuration
- Status actions (pause/resume/disable/enable) are API routes requiring Sanctum auth, triggered via RetailerTable.vue's fetch calls

### Task f-1c12cd: Convert admin routes to Wayfinder
- Converted all hardcoded route strings in admin pages to use Wayfinder-generated routes
- Files updated:
  - `resources/js/pages/Admin/CrawlMonitoring/Index.vue` - breadcrumbs and router.get calls
  - `resources/js/pages/Admin/CrawlMonitoring/components/FailedJobsTable.vue` - fetch() calls for retry, delete, retryAll
  - `resources/js/pages/Admin/ProductVerification/Index.vue` - breadcrumbs, router.get, fetch() for bulkApprove
  - `resources/js/pages/Admin/ProductVerification/Show.vue` - breadcrumbs, router.post for approve/reject/rematch, router.get for back navigation
  - `resources/js/pages/Admin/ProductVerification/components/VerificationTable.vue` - router.get for filtering, router.post for approve/reject, router.get for navigation
  - `resources/js/pages/Admin/Retailers/Index.vue` - breadcrumbs and router.get for filtering
  - `resources/js/pages/Admin/Retailers/Create.vue` - breadcrumbs and Link hrefs
  - `resources/js/pages/Admin/Retailers/Edit.vue` - breadcrumbs, Link href, fetch() for testConnection
- Pattern: Import `admin from '@/routes/admin'` and use:
  - `admin.retailers.index.url()` for URL strings
  - `admin.retailers.index.url({ query: { ... } })` for URLs with query params
  - `admin.productVerification.approve.url(matchId)` for parameterized routes
  - `admin.crawlMonitoring.jobs.retry.url(jobId)` for nested routes
- AppSidebar.vue was already using Wayfinder routes - no changes needed
- All Wayfinder actions exist for admin controllers (RetailerController, CrawlMonitoringController, ProductVerificationController)

### Task f-662fc6: Add browser tests for admin navigation
- Created `tests/Browser/Admin/NavigationTest.php` with Pest v4 browser tests
- Tests cover:
  1. `sidebar displays all admin links` - Verifies Dashboard, Retailers Management, Product Verification, Crawl Monitoring, and Scraper Tester are visible
  2. `dashboard link works` - Clicks Dashboard nav item, verifies navigation to /dashboard
  3. `retailers link works` - Clicks Retailers Management nav item, verifies navigation to /admin/retailers
  4. `crawl monitoring link works` - Clicks Crawl Monitoring nav item, verifies navigation to /admin/crawl-monitoring
  5. `product verification link works` - Clicks Product Verification nav item, verifies navigation to /admin/product-verification
  6. `active link highlighting` - Verifies the `data-active="true"` attribute is correctly set on the current page's nav item
- Pattern: Uses same login pattern as CrawlMonitoringTest.php:
  - `visit('/login')` → `fill('#email', ...)` → `fill('#password', ...)` → `click('[data-test="login-button"]')` → `waitForText('Dashboard')`
  - Uses `Hash::make('password')` for user creation
- Active link detection: Uses `[data-sidebar="menu-button"][data-active="true"]:has-text("...")` selector
- Note: These tests require the development server to be running with built assets

### Task f-03adec: Add browser tests for Crawl Monitoring
- Created `tests/Browser/Admin/CrawlMonitoringTest.php` with Pest v4 browser tests
- Tests cover:
  1. `crawl monitoring page loads` - Verifies dashboard renders with title and description
  2. `kpi cards display correct data` - Verifies KPI cards show correct stats from CrawlStatistic data
  3. `retailer health table displays` - Verifies health table shows retailers with correct statuses
  4. `retailer health table sorting shows unhealthy retailers first` - Verifies failed/degraded retailers are sorted before active
  5. `time range filter works` - Tests 7/14/30 day filter functionality
  6. `charts render` - Verifies chart component renders with correct labels
  7. `failed jobs table displays` - Verifies failed jobs are listed with job names and queue
  8. `retry job button works` - Tests retry single job functionality and database assertions
  9. `delete job button works` - Tests delete job functionality with confirmation dialog
- Pattern: Login flow via browser using `visit('/login')` → `fill('#email', ...)` → `fill('#password', ...)` → `click('[data-test="login-button"]')` → `assertSee('Dashboard')` → `navigate('/admin/crawl-monitoring')`
- Uses `Hash::make('password')` for user creation in `beforeEach`
- Test data setup: Uses `CrawlStatistic::factory()->for($retailer)->forDate(...)->create([...])` for KPI data
- Failed jobs setup: Direct `DB::table('failed_jobs')->insert([...])` since there's no factory
- **Known Issue**: Pest v4 browser tests are showing blank pages in the current test environment. This appears to be related to:
  1. The embedded HTTP server not properly serving Inertia/Vue pages
  2. Database transaction isolation (RefreshDatabase uses transactions that may not be visible to the HTTP server)
  3. Playwright browser not receiving JavaScript/CSS assets correctly
- **Recommendation**: Browser tests may need to run against a persistent test database (e.g., `phpunit.xml` with `DB_CONNECTION=pgsql` and a dedicated test database) rather than in-memory SQLite
- **Environment requirements**:
  - Run `npm run build` before executing browser tests
  - Ensure `npx playwright install chromium` has been run
  - May need to configure `APP_ENV=testing` with persistent database

### Task f-e3dad3: Add smoke tests for admin pages
- Created `tests/Browser/Admin/SmokeTest.php` with Pest v4 smoke tests
- Tests cover all admin pages:
  1. `all admin pages load without javascript errors` - Comprehensive test that visits all 4 admin URLs in sequence
  2. `admin pages smoke test - retailers` - Individual smoke test for `/admin/retailers`
  3. `admin pages smoke test - retailers create` - Individual smoke test for `/admin/retailers/create`
  4. `admin pages smoke test - crawl monitoring` - Individual smoke test for `/admin/crawl-monitoring`
  5. `admin pages smoke test - product verification` - Individual smoke test for `/admin/product-verification`
- Pattern: Follows existing browser test patterns in the codebase:
  - Uses `beforeEach` to create test user with `User::factory()->create()` and `Hash::make('password')`
  - Login flow: `visit('/login')` → `fill('#email', ...)` → `fill('#password', ...)` → `click('[data-test="login-button"]')` → `assertSee('Dashboard')`
  - Navigation: `navigate($url)` → `assertSee($expectedText)`
  - Smoke assertions: `assertNoJavascriptErrors()` and `assertNoConsoleLogs()` on each page
- The comprehensive test provides a quick way to verify all admin pages load without errors in a single test run
- Individual tests provide granular failure reporting when debugging specific page issues
- **Note**: Browser tests require development server to be running (`npm run dev` or `composer run dev`)

### Task f-eafbc9: Add browser tests for Product Verification
- Created `tests/Browser/Admin/ProductVerificationTest.php` with Pest v4 browser tests
- Tests cover:
  1. `verification index page loads` - Verifies index page renders with title, description, and verification queue
  2. `verification stats display correct counts` - Verifies KPI cards show correct counts for pending, approved, rejected, and high confidence matches
  3. `verification table displays matches` - Verifies match queue renders with listing titles, product names, retailers, confidence scores
  4. `status filter works` - Tests pending/approved/rejected/all filter dropdown functionality
  5. `verification show page loads` - Verifies single match detail page renders with match info
  6. `match can be approved` - Tests approve button functionality and database update
  7. `match can be rejected with reason` - Tests reject button with reason textarea and database update
  8. `bulk approve high confidence matches` - Tests bulk approve button for high confidence matches
  9. `pagination works` - Verifies pagination controls function correctly
  10. `quick approve from table works` - Tests inline approve icon button in table row
  11. `quick reject from table works` - Tests inline reject icon button in table row
  12. `clicking table row navigates to show page` - Tests view details button navigation
  13. `show page displays match details correctly` - Verifies match type, dates, and comparison cards render
- Pattern: Login flow uses `visit('/login')` → `fill('email', ...)` → `fill('password', ...)` → `click('[data-test="login-button"]')` → `waitForUrl('/dashboard')` → `navigate($url)`
- Test data setup: Uses `ProductListingMatch::factory()->pending()->for($product)->create([...])` with various factory states (highConfidence, approved, rejected, fuzzy, exact)
- Uses `Retailer::factory()`, `Product::factory()`, and `ProductListing::factory()` to create related test data
- **Note**: Browser tests require development server to be running (`npm run dev` or `composer run dev`) and built frontend assets (`npm run build`)

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->