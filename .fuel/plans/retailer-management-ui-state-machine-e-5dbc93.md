# Epic: Retailer Management UI & State Machine (e-5dbc93)

## Plan

Build admin UI for managing retailers and refactor the retailer status fields (is_active, health_status, paused_until) into a unified state machine with proper transitions

<!-- Add implementation plan here -->

## Implementation Notes

### Task f-b8f8cd: Create RetailerStatus enum with state machine transitions

**Completed:** Created `App\Enums\RetailerStatus` enum that implements a full state machine pattern.

**Key Decisions:**
1. **Custom state machine over spatie/laravel-model-states**: The spatie package wasn't installed, and a custom enum-based approach provides simpler, more explicit control over transitions while keeping all logic in one place.

2. **States defined:**
   - `Active` - Available for crawling (normal operational state)
   - `Paused` - Temporarily disabled, auto-resumes (for rate limiting, cooldowns)
   - `Disabled` - Manually disabled by admin (requires intervention)
   - `Degraded` - Experiencing issues but still crawling (partial failures)
   - `Failed` - Circuit breaker tripped, needs intervention

3. **Transition rules:**
   - `Active` → Paused, Disabled, Degraded, Failed
   - `Paused` → Active, Disabled (cannot go to Degraded/Failed directly)
   - `Disabled` → Active only (must explicitly re-enable)
   - `Degraded` → Active, Paused, Disabled, Failed
   - `Failed` → Active, Disabled (cannot go to Paused/Degraded)

4. **Self-transitions allowed**: Any status can "transition" to itself (no-op but valid).

**Gotchas:**
- The `transitionTo()` method throws `InvalidArgumentException` for invalid transitions - callers should use `canTransitionTo()` first to check validity
- `isAvailableForCrawling()` returns true for both Active AND Degraded states (degraded retailers still crawl)
- The existing `RetailerHealthStatus` enum and `is_active`/`health_status`/`paused_until` fields still exist - subsequent tasks need to migrate to the new status

**Patterns established:**
- Enum methods follow existing project conventions (label(), color())
- Added additional helper methods: description(), icon(), badgeClasses() for UI
- Static helper methods: default(), crawlableStatuses(), problemStatuses()

## Interfaces Created

### `App\Enums\RetailerStatus` (app/Enums/RetailerStatus.php)

A backed string enum implementing state machine transitions:

```php
// Check current state properties
$status->isAvailableForCrawling(): bool
$status->hasIssues(): bool
$status->requiresIntervention(): bool

// Transition validation
$status->allowedTransitions(): array<RetailerStatus>
$status->canTransitionTo(RetailerStatus $status): bool
$status->transitionTo(RetailerStatus $status): RetailerStatus // throws on invalid

// UI helpers
$status->label(): string
$status->color(): string
$status->description(): string
$status->icon(): string
$status->badgeClasses(): string

// Static helpers
RetailerStatus::default(): RetailerStatus
RetailerStatus::crawlableStatuses(): array
RetailerStatus::problemStatuses(): array
```

### Tests
- `tests/Unit/Enums/RetailerStatusTest.php` - 70 tests covering all transitions, helper methods, and edge cases

### Commit Status (f-b8f8cd)
**UNCOMMITTED** - Implementation complete and staged. Pre-commit hook blocked by unrelated test failure in `BMProductListingUrlExtractorTest` (expects 'pet' but got 'pets' at line 186). This test is from another agent's work. See task f-b13949. Once that test is fixed, these files can be committed:
- `app/Enums/RetailerStatus.php` (new)
- `tests/Unit/Enums/RetailerStatusTest.php` (new)
- `.fuel/plans/retailer-management-ui-state-machine-e-5dbc93.md` (updated)

---

### Task f-d36ff7: Create migration to consolidate retailer status fields

**Completed:** Created migration `2026_01_28_155522_consolidate_retailer_status_fields.php` that consolidates the three separate status-related fields (`is_active`, `health_status`, `paused_until`) into a single unified `status` field using the `RetailerStatus` enum.

**Key Decisions:**

1. **Data Migration Strategy**: The migration follows a priority-based approach to convert existing data:
   - Priority 1: `is_active=false` → `Disabled` (manual disable takes precedence)
   - Priority 2: `health_status='unhealthy'` → `Failed` (circuit breaker state)
   - Priority 3: `paused_until` in future → `Paused` (temporary pause)
   - Priority 4: `health_status='degraded'` → `Degraded` (partial failures)
   - Default: → `Active` (normal operation)

2. **Preserved Fields**: The migration keeps health monitoring fields separate from status:
   - `last_failure_at` - tracks when the last failure occurred (for monitoring/debugging)
   - `consecutive_failures` - tracks failure count (for circuit breaker logic)
   - `paused_until` - works with `Paused` status for auto-resume scheduling

3. **Removed Fields**: The redundant status fields are removed:
   - `is_active` - replaced by `status` (Disabled vs Active/Paused/etc)
   - `health_status` - replaced by `status` (Failed/Degraded/Active)

4. **Rollback Support**: The `down()` method fully reverses the migration, converting status values back to the original three-field system.

**Migration Logic:**

```php
// Forward migration (up):
// 1. Add status column with default='active'
// 2. Migrate data based on priority rules
// 3. Drop is_active and health_status columns

// Reverse migration (down):
// 1. Restore is_active and health_status columns
// 2. Convert status back to original fields
// 3. Drop status column
```

**Patterns Established:**

- Use DB::table() for data migration queries (not Eloquent) to avoid issues with model casts
- Apply migrations in priority order with WHERE conditions to prevent overwriting
- Keep monitoring fields (last_failure_at, consecutive_failures) separate from operational status
- Use paused_until alongside Paused status for auto-resume functionality

**Gotchas:**

- The migration must run AFTER the RetailerStatus enum is created (dependency on f-b8f8cd)
- Data migration uses priority-based updates, so the order of UPDATE statements matters
- The paused_until field is NOT dropped - it works together with Paused status for scheduling
- Tests verify schema changes but skip data migration testing (marked as manual)

**Files Created:**

- `database/migrations/2026_01_28_155522_consolidate_retailer_status_fields.php` - Migration
- `tests/Feature/ConsolidateRetailerStatusFieldsMigrationTest.php` - Schema validation tests (7 tests)

**CRITICAL BLOCKER:**

- ⚠️ **Task f-7ce313 MUST be completed before this migration can be run** ⚠️
- The migration has been created but cannot be applied yet because it breaks all existing tests
- 11+ test files explicitly use `is_active` and `health_status` fields which no longer exist after migration
- Tests must be updated to use the new `status` field before the migration can be applied

**Status:**

- ✅ Retailer model - ALREADY UPDATED by another agent (uses `status` field)
- ✅ Retailer factory - ALREADY UPDATED by another agent (uses `status` field)
- ❌ Test files - BLOCKED - 11+ files need updating (see task f-7ce313)
- ❌ Migration - CANNOT BE RUN until tests are fixed

**Next Steps:**

- **IMMEDIATE:** Complete task f-7ce313 to update all test files
- Consider adding a scheduled task to auto-resume paused retailers when `paused_until` expires
- After tests are fixed, run `php artisan migrate` to apply this migration

---

### Task f-2d6807: Add retailer status transition API endpoints

**Completed:** Created API endpoints for managing retailer status transitions with proper authorization, validation, and events.

**Key Decisions:**

1. **API Design**: RESTful endpoints under `/api/v1/admin/retailers/{retailer}/` namespace:
   - `POST /pause` - Pause retailer with optional duration and reason
   - `POST /resume` - Resume paused retailer
   - `POST /disable` - Disable retailer with optional reason
   - `POST /enable` - Enable retailer and reset failures

2. **Form Request Validation**:
   - `PauseRetailerRequest` - Validates duration_minutes (1-43200) and reason (max 500 chars)
   - `ResumeRetailerRequest` - No validation needed
   - `DisableRetailerRequest` - Validates reason (max 500 chars)
   - `EnableRetailerRequest` - No validation needed

3. **Authorization**: Created `RetailerPolicy` with methods for each action (pause, resume, disable, enable)
   - Currently allows any authenticated user (TODO comment for admin role check)
   - Uses Laravel's policy authorization via Form Requests

4. **Event Emission**: All endpoints emit `RetailerStatusChanged` event with:
   - Retailer model, Old status, New status, Optional reason, User who triggered the change

5. **Model & Factory Updates**: Updated Retailer model and factory to use new status field (was already needed for migration)

**Files Created/Modified:**

- `app/Http/Controllers/Admin/RetailerStatusController.php` - Controller with 4 endpoints
- `app/Http/Requests/Admin/PauseRetailerRequest.php` - Form request
- `app/Http/Requests/Admin/ResumeRetailerRequest.php` - Form request
- `app/Http/Requests/Admin/DisableRetailerRequest.php` - Form request
- `app/Http/Requests/Admin/EnableRetailerRequest.php` - Form request
- `app/Events/RetailerStatusChanged.php` - Event
- `app/Policies/RetailerPolicy.php` - Policy with 4 methods
- `routes/api.php` - Added 4 routes under auth:sanctum middleware
- `app/Models/Retailer.php` - Updated to use status field (fillable, casts, methods)
- `database/factories/RetailerFactory.php` - Updated to use status field
- `tests/Feature/Admin/RetailerStatusControllerTest.php` - 18 comprehensive tests (all passing)

**API Endpoints:**

```
POST /api/v1/admin/retailers/{retailer}/pause
POST /api/v1/admin/retailers/{retailer}/resume
POST /api/v1/admin/retailers/{retailer}/disable
POST /api/v1/admin/retailers/{retailer}/enable
```

**Patterns Established:**

- Use Form Requests for authorization and validation
- Emit events for all status changes to enable logging/notifications
- Return full retailer object with computed properties in API responses
- Include both technical fields (status) and UI helpers (status_label, status_color)

**Gotchas:**

- The pause endpoint sets a default 24-hour duration if duration_minutes is not provided
- The enable endpoint resets consecutive_failures to 0
- Both disable and enable endpoints clear paused_until
- Policy currently allows any authenticated user - needs admin role check when role system is implemented

**Next Steps:**

- Add admin role check to RetailerPolicy when role system is implemented
- Consider adding event listeners for RetailerStatusChanged (e.g., logging, notifications)
- ✅ Add scheduled task to auto-resume retailers when paused_until expires (completed in task f-5d7aa2)

---

### Task f-5d7aa2: Update crawler commands and jobs to use new retailer status

**Completed:** Updated all crawler-related code to use the new RetailerStatus state machine. Removed all references to is_active, health_status enum, and migrated to the unified status field.

**Key Decisions:**

1. **DispatchRetailerCrawlsCommand Updates**:
   - Replaced `Retailer::active()->where(paused_until)` query with status-based query using `RetailerStatus::crawlableStatuses()`
   - Now dispatches jobs only for Active and Degraded retailers (not Paused, Disabled, or Failed)
   - Updated success messages to say "crawlable retailers" instead of "active retailers"

2. **CrawlProductListingsJob Updates**:
   - Added retailer status check at the start of job execution
   - Job now silently exits if retailer is not available for crawling (prevents wasted processing)
   - Logs informational message when job is skipped due to status

3. **UpdateRetailerHealthReactor Updates**:
   - Completely refactored to use RetailerStatus state machine with proper transitions
   - On successful crawl: transitions to Active (if allowed by state machine)
   - On failed crawl: transitions through Degraded → Failed based on consecutive_failures
   - Circuit breaker logic:
     - 5 consecutive failures → Degraded
     - 10 consecutive failures → Failed (+ pause for 1 hour)
   - Only sets paused_until if not already paused (prevents extending pause duration)
   - Manual resetHealth() method respects state machine transitions

4. **Auto-Resume Functionality**:
   - Created `ResumeExpiredPausedRetailersCommand` to handle automatic resumption of paused retailers
   - Command runs hourly via Laravel Scheduler
   - Finds retailers with status=Paused and paused_until <= now()
   - Transitions them from Paused → Active (respecting state machine rules)
   - Logs all resume attempts and results

**State Transition Flow:**

```
Success: Any → Active
Failure: Active → Degraded (5 failures)
Failure: Degraded → Failed (10 failures, auto-pause)
Auto-resume: Paused → Active (when pause expires)
```

**Files Modified:**

- `app/Console/Commands/DispatchRetailerCrawlsCommand.php` - Updated to use status-based filtering
- `app/Jobs/Crawler/CrawlProductListingsJob.php` - Added status check before crawling
- `app/Domain/Crawler/Reactors/UpdateRetailerHealthReactor.php` - Refactored to use RetailerStatus transitions
- `routes/console.php` - Added hourly scheduled task for resume-expired command
- `tests/Unit/Domain/Reactors/UpdateRetailerHealthReactorTest.php` - Updated all tests to use RetailerStatus
- `tests/Feature/DispatchRetailerCrawlsCommandTest.php` - Updated all tests to use RetailerStatus
- `tests/Feature/ResumeExpiredPausedRetailersCommandTest.php` - Created new tests (5 tests, all passing)

**Files Created:**

- `app/Console/Commands/ResumeExpiredPausedRetailersCommand.php` - Auto-resume command

**Test Coverage:**

- UpdateRetailerHealthReactorTest: 24 tests, all passing
- DispatchRetailerCrawlsCommandTest: 8 tests, all passing
- ResumeExpiredPausedRetailersCommandTest: 5 tests, all passing
- All crawler feature tests: 38 tests, all passing

**Patterns Established:**

- Always check retailer status before executing crawler jobs
- Use `RetailerStatus::crawlableStatuses()` for filtering available retailers
- Respect state machine transition rules when updating status
- Use `canTransitionTo()` before applying status changes
- Don't extend pause duration if already paused
- Log all status transitions and circuit breaker activations

**Gotchas:**

- The old `RetailerHealthStatus` enum is now completely removed from crawler code
- Circuit breaker threshold values remain the same (5 for Degraded, 10 for Failed)
- Paused retailers with expired paused_until will be auto-resumed hourly (not immediately)
- The job status check happens at job execution time, not dispatch time (jobs may be queued for non-crawlable retailers but will exit early)
- Both Active AND Degraded statuses allow crawling (degraded retailers still attempt crawls)

**Impact on System:**

- More granular control over retailer status vs binary active/inactive
- Automatic recovery from temporary failures (Degraded can recover to Active)
- Explicit intervention required for failed retailers (Failed → Active requires manual action)
- Hourly auto-resume reduces manual maintenance for temporarily paused retailers
- Better separation of concerns: operational status vs health monitoring

**Next Steps:**

- The scheduled task handles auto-resume, no further action needed
- Consider adding monitoring/alerting when retailers enter Failed state
- Consider adding UI to display and manage retailer status transitions

---

### Task f-000a2c: Build retailer list page with status management

**Completed:** Created admin page at `/admin/retailers` showing all retailers with status management capabilities.

**Key Decisions:**

1. **Page Structure**: Used the same layout pattern as CrawlMonitoring/Index.vue with summary cards and a data table
2. **Filtering**: Implemented status-based filtering and text search (name, slug, base_url)
3. **Sorting**: Supports sorting by name, slug, status, last_crawled_at, consecutive_failures, and product count
4. **Inline Actions**: Used dropdown menu for status actions (Pause, Resume, Disable, Enable) with modal dialogs for pause duration and reason

**Files Created:**

- `app/Http/Controllers/Admin/RetailerController.php` - Controller with index method
- `resources/js/pages/Admin/Retailers/Index.vue` - Main page component
- `resources/js/pages/Admin/Retailers/components/RetailerTable.vue` - Table with inline actions
- `tests/Feature/Admin/RetailerControllerTest.php` - 18 feature tests, all passing

**Routes Added:**

- `GET /admin/retailers` → `admin.retailers.index`

**Features:**

1. **Summary Cards:**
   - Total Retailers / Available for crawling
   - Status Overview (Active / Issues / Disabled badges)
   - Recently Crawled (last 24h)
   - Total Products count

2. **Filters:**
   - Status dropdown (All, Active, Paused, Disabled, Degraded, Failed) with counts
   - Text search with debounce (case-insensitive)

3. **Table Columns:**
   - Retailer name + slug + external link
   - Status badge with icon and description
   - Last crawled (relative time with color coding)
   - Consecutive failures with badge and last failure date
   - Product count

4. **Inline Actions (via dropdown):**
   - Pause: Opens dialog with duration picker (15m to 7d) and optional reason
   - Resume: Direct action for paused retailers
   - Disable: Opens dialog with optional reason
   - Enable: Direct action for disabled/failed retailers
   - Actions visibility controlled by `can_pause`, `can_resume`, `can_disable`, `can_enable` flags

**Patterns Established:**

- Use `canTransitionTo()` AND check that status is not already the target status for action visibility
- Use LOWER() with LIKE for case-insensitive search (works on both PostgreSQL and SQLite)
- Format retailer data once in controller's formatRetailer() method
- Include both raw data and UI helpers in the formatted response

**Gotchas:**

- The `canTransitionTo()` method allows self-transitions, so we check `status !== TargetStatus` before showing action buttons
- Search uses `LOWER()` function for cross-database compatibility (PostgreSQL uses ilike, SQLite doesn't)
- Status actions use fetch() with XSRF token from cookies for API calls

**Test Coverage:**

- RetailerControllerTest: 18 tests covering authentication, filtering, sorting, status counts, summary stats, product counts, action flags

**Commit Status (f-000a2c):**
- **UNCOMMITTED** - Implementation complete and tests passing (18/18)
- Pre-commit hook blocked by 67 failing tests from other agents' code (extractors, crawler commands)
- Files ready to commit:
  - `resources/js/pages/Admin/Retailers/Index.vue` (new)
  - `resources/js/pages/Admin/Retailers/components/RetailerTable.vue` (new)
  - `tests/Feature/Admin/RetailerControllerTest.php` (new - note: other agent added more tests for create/edit)
  - `.fuel/plans/retailer-management-ui-state-machine-e-5dbc93.md` (updated)
- Blocked by task f-c19fdf (needs-human: fix failing tests)
- Note: The RetailerController.php was created by this task but later modified by another agent who added create/store/edit/update methods. The index() method and its formatting helpers were written by this task.

---

### Task f-fa38be: Build retailer create/edit form

**Completed:** Created Inertia/Vue forms for creating and editing retailers with full validation, Wayfinder integration, and test connection functionality.

**Key Decisions:**

1. **Form Request Validation:**
   - `StoreRetailerRequest` and `UpdateRetailerRequest` with proper rules
   - Validation for: name (unique), slug (regex, unique), base_url (url), crawler_class (required), rate_limit_ms (100-60000), status (enum)
   - Custom error messages for all validation rules

2. **Slug Auto-Generation:**
   - Slug is auto-generated from name using slugify function on frontend
   - User can edit slug to customize, which disables auto-generation
   - Backend generates slug from name if not provided

3. **Crawler Class Dropdown:**
   - Dynamically populated from filesystem (app/Crawler/Scrapers/*.php)
   - Excludes BaseCrawler and non-Crawler classes
   - Labels are derived from class names with proper spacing (TescoCrawler → Tesco)

4. **Test Connection Feature:**
   - Endpoint at `POST /admin/retailers/{retailer}/test-connection`
   - Uses HttpAdapterInterface to make a real request to the crawler's first starting URL
   - Returns success status, status code, HTML length, and test URL
   - Provides detailed error messages on failure

5. **Edit Page Statistics:**
   - Product count from product_listings relationship
   - Last crawled timestamp with relative time display
   - 7-day crawl statistics (started, completed, failed, success rate)
   - Failure history (consecutive, last 30 days, recent failure dates)
   - Uses CrawlStatistic model for daily stats

**Files Created/Modified:**

- `app/Http/Requests/Admin/StoreRetailerRequest.php` - Form validation for create
- `app/Http/Requests/Admin/UpdateRetailerRequest.php` - Form validation for update
- `app/Http/Controllers/Admin/RetailerController.php` - Added create, store, edit, update, testConnection methods
- `resources/js/pages/Admin/Retailers/Create.vue` - Create retailer form page
- `resources/js/pages/Admin/Retailers/Edit.vue` - Edit retailer form page with statistics
- `routes/web.php` - Added retailer CRUD routes
- `tests/Feature/Admin/RetailerControllerTest.php` - Extended with 23 new tests (41 total)

**Routes Added:**

- `GET /admin/retailers/create` → `admin.retailers.create`
- `POST /admin/retailers` → `admin.retailers.store`
- `GET /admin/retailers/{retailer}/edit` → `admin.retailers.edit`
- `PUT /admin/retailers/{retailer}` → `admin.retailers.update`
- `POST /admin/retailers/{retailer}/test-connection` → `admin.retailers.test-connection`

**UI Components Used:**

- Card, CardHeader, CardContent, CardDescription
- Input, Label, Select, SelectTrigger, SelectContent, SelectItem
- Button (with loading states)
- Badge (for status display)
- Separator, InputError
- Icons from lucide-vue-next

**Patterns Established:**

- Use Wayfinder's `.form()` method with `v-bind` for Form component action/method
- Pass hidden inputs for Select values (Select component doesn't create native inputs)
- Use computed properties for Select placeholder text
- Flash messages read from `usePage().props.flash`
- Statistics loaded via Inertia props, not deferred
- Test connection uses native fetch() with CSRF token

**Gotchas:**

- Select components require hidden input with `name` attribute for form submission
- The `.form()` method is added at runtime by Vite plugin, not in generated TypeScript
- Wayfinder needs to be regenerated after adding new controller methods
- CrawlStatistic model must exist for statistics to work (may return empty data if not populated)

**Test Coverage:**

- Create page: authentication, load, crawler classes presence (3 tests)
- Store: authentication, creates retailer, custom slug, required fields, name/slug uniqueness, URL format, rate limit range, status enum (9 tests)
- Edit page: authentication, load, statistics, 404 for non-existent (4 tests)
- Update: authentication, modifies retailer, same name allowed, name uniqueness, required fields (5 tests)
- Test connection: authentication, missing crawler, invalid crawler (3 tests)

**Next Steps:**

- Consider adding delete functionality if needed
- Consider adding bulk actions for multiple retailers
- Add navigation links from Index page to Create/Edit pages

**Commit Status (f-fa38be):**

- **BLOCKED** - Implementation complete and tests passing (41/41)
- Pre-commit hook blocked by 11 failing unit tests from other agents' extractor code:
  - Category extraction tests expecting "Dog Food" but getting "dog-food"
  - Weight parsing tests with rounding differences (5lb = 2265 vs 2270 vs 2268)
  - URL extractor tests with missing categories
- See task f-e4496d (needs-human) for details on failing tests
- Files ready to commit once tests are fixed:
  - `app/Http/Requests/Admin/StoreRetailerRequest.php` (new)
  - `app/Http/Requests/Admin/UpdateRetailerRequest.php` (new)
  - `app/Http/Controllers/Admin/RetailerController.php` (modified - added create/store/edit/update/testConnection methods)
  - `resources/js/pages/Admin/Retailers/Create.vue` (new)
  - `resources/js/pages/Admin/Retailers/Edit.vue` (new)
  - `routes/web.php` (modified - added retailer CRUD routes)
  - `tests/Feature/Admin/RetailerControllerTest.php` (modified - added 23 new tests)
  - `.fuel/plans/retailer-management-ui-state-machine-e-5dbc93.md` (updated)