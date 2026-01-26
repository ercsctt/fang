# Epic: Complete Fang Crawler Infrastructure (e-2248fe)

## Plan

Finish the crawler infrastructure including scheduling, additional retailers, tests, reactors, product matching, and review extraction

<!-- Add implementation plan here -->

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->

### Crawler Scheduling (f-714cce) - Completed
- Created `DispatchRetailerCrawlsCommand` (`app/Console/Commands/DispatchRetailerCrawlsCommand.php`)
  - Command signature: `crawler:dispatch-all`
  - Options: `--retailer=` (filter by slug), `--queue=crawler` (default queue), `--delay=0` (base delay in seconds between retailers)
  - Iterates through active retailers, instantiates their crawler classes, and dispatches `CrawlProductListingsJob` for each starting URL
  - Staggered delays: base delay between retailers, plus 5 seconds between each URL within a retailer
  - Updates `last_crawled_at` timestamp on successful dispatch
- Added scheduling to `routes/console.php`:
  - Daily at 02:00 UK time (Europe/London timezone)
  - Uses `withoutOverlapping(expiresAt: 180)` to prevent concurrent crawls
  - Uses `onOneServer()` for distributed deployments on Laravel Cloud
  - Logs output to `storage/logs/crawler-schedule.log`
  - Weekly review scraping scaffold (commented out, pending CrawlProductReviewsCommand)

**Gotchas:**
- When instantiating crawlers dynamically from `$retailer->crawler_class`, use `new $retailer->crawler_class(new GuzzleHttpAdapter)` directly - don't try to use `app()` with a default parameter as the syntax is different
- BMCrawler returns 4 starting URLs for B&M's pet food sections

### Pets at Home Crawler (f-7ae42d) - Completed
- Created `PetsAtHomeCrawler` (`app/Crawler/Scrapers/PetsAtHomeCrawler.php`)
  - Extends `BaseCrawler`, registers both extractors
  - Starting URLs: 6 URLs covering dog food (dry/wet), dog treats, puppy food, and puppy treats
  - Request delay: 2000ms (2 seconds between requests)
  - Custom headers: Accept-Language and Accept headers for UK content

- Created `PAHProductListingUrlExtractor` (`app/Crawler/Extractors/PetsAtHome/PAHProductListingUrlExtractor.php`)
  - Extracts product URLs from category pages
  - URL pattern: `/product/[slug]/[PRODUCTCODE]` (e.g., `/product/wainwrights-salmon/P71341`)
  - Product codes can be alphanumeric (P71341, 7136893P)
  - Extracts category from source URL path (dog-food, dog-treats, etc.)

- Created `PAHProductDetailsExtractor` (`app/Crawler/Extractors/PetsAtHome/PAHProductDetailsExtractor.php`)
  - Prioritizes JSON-LD structured data extraction (most reliable)
  - Falls back to DOM selectors if JSON-LD unavailable
  - Extracts: title, description, brand, price (from offers array), images (from CDN), ingredients, stock status, external ID
  - Includes rating_value and review_count in metadata when available
  - 80+ known pet food brands for brand extraction fallback

- Created `CrawlPetsAtHomeProductListings` command (`app/Console/Commands/CrawlPetsAtHomeProductListings.php`)
  - Command signature: `crawler:petsathome`
  - Options: `--queue=`, `--sync`
  - Same pattern as CrawlBMProductListings command

- Updated `RetailerSeeder` to set `crawler_class` for Pets at Home and increased `rate_limit_ms` to 2000ms

**Patterns established:**
- Retailer-specific extractors go in subdirectories: `app/Crawler/Extractors/{RetailerName}/`
- Extractor naming: `{Prefix}ProductListingUrlExtractor.php`, `{Prefix}ProductDetailsExtractor.php`
- Always try JSON-LD structured data first for product details (Schema.org Product type)
- Use `cdn.petsathome.com` for image URLs

**Gotchas:**
- Pets at Home JSON-LD has `offers` as an array (multiple size/price options) - use first offer for default price
- Product codes in URLs can have letters before/after numbers (P71341, 7136893P)
- JSON-LD brand can be a string or an object with `name` property - handle both
- Category pages have 1600+ products, pagination may be needed for full crawls

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->