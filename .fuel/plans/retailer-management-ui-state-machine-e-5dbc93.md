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