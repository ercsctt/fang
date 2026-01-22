# Reality

## Architecture
Laravel 12 + Inertia v2 + Vue 3 product aggregation system for UK pet food retailers. Uses event sourcing (Spatie) for crawl operations with Eloquent for read models. Crawler system uses adapter pattern for HTTP/proxy abstraction.

## Modules
| Module | Purpose | Entry Point |
|--------|---------|-------------|
| Crawler/Scrapers | Retailer-specific crawlers | `app/Crawler/Scrapers/BaseCrawler.php` |
| Crawler/Extractors | HTML data extraction (yields DTOs) | `app/Crawler/Extractors/` |
| Crawler/Adapters | HTTP fetching abstraction | `app/Crawler/Adapters/GuzzleHttpAdapter.php` |
| Crawler/Proxies | Proxy rotation (BrightData) | `app/Crawler/Proxies/ProxyManager.php` |
| Domain/Crawler | Event sourcing aggregates/events | `app/Domain/Crawler/Aggregates/CrawlAggregate.php` |
| Jobs/Crawler | Queued crawl jobs | `app/Jobs/Crawler/CrawlProductListingsJob.php` |
| Models | Eloquent models (Product, Retailer, etc.) | `app/Models/` |

## Entry Points
- **Web**: `routes/web.php` → Inertia pages + scraper tester
- **Console**: `app/Console/Commands/CrawlBMProductListings.php`
- **Queue**: `app/Jobs/Crawler/` (dispatched via event sourcing reactors)
- **Bootstrap**: `bootstrap/app.php` (middleware, routing)

## Patterns
- **Event Sourcing**: Crawl operations tracked via `CrawlAggregate` with events (CrawlStarted, ProductListingDiscovered, etc.)
- **Adapter Pattern**: `HttpAdapterInterface` + `ProxyAdapterInterface` for pluggable HTTP/proxy
- **Generator/DTO**: Extractors yield typed DTOs (ProductListingUrl, ProductDetails, ProductPrice, etc.)
- **Constructor Promotion**: PHP 8+ style throughout
- **Form Requests**: Validation in dedicated request classes

## Quality Gates
| Tool | Command | Purpose |
|------|---------|---------|
| Pint | `vendor/bin/pint` | PHP code style (PSR-12) |
| Pest | `php artisan test` or `vendor/bin/pest` | PHP unit/feature tests |
| ESLint | `npm run lint` | TypeScript/Vue linting |
| Prettier | `npm run format` | Frontend formatting |
| Prettier Check | `npm run format:check` | Frontend format check |
| Fuel QC | `fuel qc` | Pre-commit (runs Pint + Pest) |

## Recent Changes
_Last updated: never_
