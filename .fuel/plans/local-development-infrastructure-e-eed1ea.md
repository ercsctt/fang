# Epic: Local Development Infrastructure (e-eed1ea)

## Plan

Set up local development infrastructure using Docker Compose with MySQL, Redis, and Meilisearch. Configure environment and ensure seamless local development experience.

<!-- Add implementation plan here -->

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->

### f-b479e9: Add Meilisearch to docker-compose
- Added Meilisearch v1.6 service with container name `fang-meilisearch`
- Port 7700 exposed for local access
- Uses `MEILISEARCH_KEY` env var (defaults to `masterKey` for local dev)
- Data persisted in `meilisearch-data` volume
- Healthcheck uses wget to hit `/health` endpoint (Meilisearch Alpine image includes wget)

### f-55fbd2: Update .env.example with Meilisearch config
- Added `MEILISEARCH_HOST=http://127.0.0.1:7700` and `MEILISEARCH_KEY=masterKey` after Redis section
- Scout is not currently installed, so no SCOUT_DRIVER update needed
- All docker service hosts already use 127.0.0.1 for local dev

### f-993dc9: Configure Laravel Scout with Meilisearch
- Installed `laravel/scout` v10.23 and `meilisearch/meilisearch-php` v1.16
- Published Scout config to `config/scout.php`
- Set default driver to `meilisearch` (reads from `SCOUT_DRIVER` env var)
- Added `SCOUT_DRIVER=meilisearch` to `.env.example`
- Added `Searchable` trait to `Product` and `ProductListing` models
- Implemented `toSearchableArray()` on both models:
  - Product: id, name, brand, description, category, canonical_category, subcategory
  - ProductListing: id, title, description, brand, category, barcode, retailer_id
- Added `SCOUT_DRIVER=collection` to `phpunit.xml` so tests don't require running Meilisearch instance
- **Key decision**: Use `collection` driver in tests to avoid external service dependency

### f-3e3b11: Configure Redis for queues and cache
- Updated `QUEUE_CONNECTION=database` to `QUEUE_CONNECTION=redis` in `.env.example`
- Updated `CACHE_STORE=database` to `CACHE_STORE=redis` in `.env.example`
- Verified `REDIS_HOST=127.0.0.1` and `REDIS_PORT=6379` already match the docker-compose Redis service
- No `.env` file exists (ignored by git), only `.env.example` was updated
- This improves local dev performance by using Redis instead of database-based queues/cache

### f-833c89: Create dev services health check command
- Created `php artisan dev:check-services` command at `app/Console/Commands/CheckDevServicesCommand.php`
- Checks PostgreSQL via `DB::connection()->getPdo()`
- Checks Redis via `Redis::ping()` (handles both `true` and `'PONG'` responses)
- Checks Meilisearch via HTTP GET to `/health` endpoint using `config('scout.meilisearch.host')`
- Uses Laravel Prompts (`info()`, `error()`) for styled output
- Green checkmark for success, red X for failures
- Summary shows pass count and lists failing services with docker compose instructions
- Returns exit code 0 on success, 1 on any failure
- Error messages are simplified (e.g., "Connection refused - is the service running?") and truncated to 100 chars

### f-159e5e: Add docker convenience scripts
- Added composer scripts following existing project conventions (no Makefile)
- Scripts added to `composer.json`:
  - `docker:up` - Start containers in detached mode
  - `docker:down` - Stop containers
  - `docker:logs` - Follow container logs
  - `docker:fresh` - Remove volumes and restart (clean slate)
- Usage: `composer docker:up`, `composer docker:down`, etc.
- **Key decision**: Used Composer scripts (Option A) since project already uses them extensively

### f-d0d490: Create one-command dev setup script
- Created `php artisan dev:setup` command at `app/Console/Commands/DevSetupCommand.php`
- Added `composer dev:setup` script alias in `composer.json`
- Steps automated in order:
  1. Copy `.env.example` to `.env` if `.env` doesn't exist
  2. Generate app key if `APP_KEY=` is empty
  3. Start Docker services (`docker compose up -d`)
  4. Wait for services to be healthy (polls `docker compose ps --format json`, max 30 attempts with 2s interval)
  5. Run migrations (`php artisan migrate --force`)
  6. Run seeders (`php artisan db:seed --force`)
  7. Generate Wayfinder routes (`php artisan wayfinder:generate`)
  8. Install npm dependencies (`npm install`)
  9. Build frontend assets (`npm run build`)
- Skip flags available: `--skip-docker`, `--skip-npm`, `--skip-migrate`, `--skip-seed`, `--skip-wayfinder`
- Uses Laravel Prompts for styled output (green ✓ for success, red ✗ for failure, yellow ⊘ for skipped)
- Docker health check parses NDJSON output and verifies all containers have State=running and Health=healthy
- Success message shows next steps: `composer run dev`, `php artisan dev:check-services`, localhost URL
- Comprehensive test coverage in `tests/Feature/DevSetupCommandTest.php`

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->