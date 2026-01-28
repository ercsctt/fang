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

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->