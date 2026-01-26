# Reality

## Architecture
**Fang** is a Laravel 12 product aggregation system for scraping UK pet retailers at scale. It uses:
- **Event Sourcing** (Spatie) for crawl audit trails
- **Adapter Pattern** for swappable HTTP clients (Guzzle + BrightData proxies)
- **Generator-based Extractors** yielding DTOs for memory efficiency
- **Inertia.js v2 + Vue 3 + Tailwind v4** frontend
- **PostgreSQL** database, **Redis** queues

## Modules
| Module | Purpose | Entry Point |
|--------|---------|-------------|
| Crawler/Adapters | HTTP client abstraction with proxy/UA rotation | `GuzzleHttpAdapter.php` |
| Crawler/Extractors | DOM scraping, yields DTOs | `BMProductListingUrlExtractor.php` |
| Crawler/Proxies | BrightData residential proxy integration | `ProxyManager.php` |
| Crawler/Scrapers | Retailer-specific crawlers | `BMCrawler.php` |
| Domain/Crawler | Event sourcing aggregates/events/projectors | `CrawlAggregate.php` |
| Jobs/Crawler | Queued crawl jobs with retry/backoff | `CrawlProductListingsJob.php` |
| Models | Eloquent ORM (Retailer, ProductListing, etc.) | `ProductListing.php` |
| Http/Controllers | Web routes + ScraperTester | `ScraperTesterController.php` |
| Frontend | Inertia pages, Vue components | `resources/js/pages/` |

## Entry Points
- **Console**: `php artisan crawler:bm` - triggers B&M crawl jobs
- **Jobs**: `CrawlProductListingsJob::dispatch()` - background crawling
- **Web**: `/scraper-tester` - authenticated crawler testing UI
- **Event Sourcing**: `CrawlAggregate::retrieve($id)` - records crawl events
- **Projectors**: `ProductListingProjector` - auto-creates listings from events

## Patterns
- **Strict typing**: `declare(strict_types=1)` in all PHP files
- **Constructor promotion**: `public function __construct(public readonly Type $prop)`
- **Generators**: Extractors `yield` DTOs, never return arrays
- **Eloquent scopes**: `inStock()`, `needsScraping(hours)`, `byRetailer()`
- **Factories + Seeders**: All models have factories for testing
- **Vue script setup**: `<script setup lang="ts">` composition API
- **Wayfinder routes**: Import from `@/actions/` or `@/routes/`

## Quality Gates
| Tool | Command | Purpose |
|------|---------|---------|
| Pint | `vendor/bin/pint --dirty` | PHP code formatting (PSR-12) |
| Pest | `php artisan test` | PHP test runner |
| ESLint | `npm run lint` | TypeScript/Vue linting |
| Prettier | `npm run format` | Frontend code formatting |
| vue-tsc | `npm run build` | TypeScript type checking |
| CI (lint.yml) | Push to main/develop | Pint, Prettier, ESLint |
| CI (tests.yml) | Push to main/develop | Pest, npm build |
| Pre-commit | `fuel qc` | Quality gate hook |

## Recent Changes
_Last updated: never_
