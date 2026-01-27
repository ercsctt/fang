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

### Review Extraction (f-55feaf) - Completed
- Created `BMProductReviewsExtractor` (`app/Crawler/Extractors/BM/BMProductReviewsExtractor.php`)
  - Implements `ExtractorInterface`
  - Extracts reviews from JSON-LD structured data first (most reliable)
  - Falls back to DOM-based extraction if no JSON-LD reviews found
  - Yields `ProductReview` DTOs with: externalId, rating, author, title, body, verifiedPurchase, reviewDate, helpfulCount, metadata
  - Handles various rating formats: data attributes, star counts, text-based ratings
  - Generates unique external IDs when not provided by source

- Created `CrawlProductReviewsJob` (`app/Jobs/Crawler/CrawlProductReviewsJob.php`)
  - Queued job for crawling reviews for a single ProductListing
  - Uses extractor class passed in constructor (supports different retailers)
  - Upserts reviews via `external_id` unique constraint (prevents duplicates)
  - Updates `last_reviews_scraped_at` on ProductListing after successful crawl
  - Timeout: 180s, Tries: 3, Backoff: 60s

- Created `CrawlProductReviewsCommand` (`app/Console/Commands/CrawlProductReviewsCommand.php`)
  - Command signature: `crawler:reviews`
  - Options: `--listing-id=` (single listing), `--retailer=` (by slug), `--days=7` (scrape interval), `--queue=crawler`, `--limit=`, `--sync`
  - Maintains extractor map: retailer slug -> extractor class
  - Currently supports: `bm` -> `BMProductReviewsExtractor`

- Added `last_reviews_scraped_at` column to `product_listings` table
  - Migration: `2026_01_26_152146_add_last_reviews_scraped_at_to_product_listings_table.php`

- Added `scopeNeedsReviewScraping` to `ProductListing` model
  - Returns listings with null or stale `last_reviews_scraped_at`

- Updated scheduling in `routes/console.php`:
  - Weekly on Sunday at 03:00 UK time
  - Uses `withoutOverlapping(expiresAt: 240)` and `onOneServer()`
  - Logs to `storage/logs/crawler-reviews.log`

**Patterns established:**
- Review extractors live in retailer-specific subdirectories: `app/Crawler/Extractors/{RetailerName}/`
- Review extractor naming: `{Prefix}ProductReviewsExtractor.php`
- Always try JSON-LD structured data first for reviews (Schema.org Review type)
- Generate deterministic external IDs when not provided: `{retailer}-review-{md5(url+author+body)}-{index}`
- Deduplication via unique constraint on `(product_listing_id, external_id)`

**Gotchas:**
- JSON-LD reviews can have author as string or object with `name` property - handle both
- Rating can be in `reviewRating.ratingValue` or directly on review object
- DOM rating extraction needs to look inside nested elements, not just on the review container itself
- When no reviews found in JSON-LD, fall back to DOM selectors

**Testing:**
- Unit tests: `tests/Unit/Crawler/Extractors/BM/BMProductReviewsExtractorTest.php` (26 tests)
- Feature tests: `tests/Feature/Crawler/CrawlProductReviewsJobTest.php` (15 tests)
- Test fixture: `tests/Fixtures/bm-product-page-with-reviews.html`

### Product Matching (f-2ff2bb) - Completed
- Created `ProductNormalizer` service (`app/Services/ProductNormalizer.php`)
  - `normalizeTitle()`: Normalizes product titles for comparison (lowercase, removes weight/quantity info, removes common phrases like "complete", "premium", "natural")
  - `normalizeBrand()`: Normalizes brand names with known aliases (e.g., "lily's kitchen" → "Lily's Kitchen")
  - `extractWeightFromTitle()`: Extracts weight in grams from title strings (supports kg, g, lb, oz)
  - `extractQuantityFromTitle()`: Extracts pack size (e.g., "12x400g" → 12)
  - `normalizeWeight()`: Converts weight values to grams
  - `weightsMatch()`: Compares weights with configurable tolerance (default 5%)
  - `brandsMatch()`: Case-insensitive brand comparison with alias support
  - `calculateTitleSimilarity()`: Uses both `similar_text()` and `levenshtein()` algorithms, returns best score

- Created `ProductMatcher` service (`app/Services/ProductMatcher.php`)
  - `match(ProductListing $listing, bool $createProductIfNoMatch = true)`: Main entry point
    - Returns existing match if already matched (idempotent)
    - Tries exact match first (confidence >= 95%)
    - Falls back to fuzzy match (confidence >= 70% and < 95%)
    - Creates new Product if no match found and `createProductIfNoMatch` is true
  - `findExactMatch()`: Requires brand match + title similarity >= 95% + weight match
  - `findFuzzyMatch()`: Uses title similarity >= 70% for broader matching
  - `calculateConfidence()`: Weighted scoring:
    - Title similarity: 50% weight
    - Brand match: 25% weight
    - Weight match: 15% weight
    - Quantity match: 10% weight
  - Updates Product price statistics (lowest_price_pence, average_price_pence) after matching

- Created `MatchProductListingJob` (`app/Jobs/Crawler/MatchProductListingJob.php`)
  - Queued job for matching a single ProductListing
  - Uses dependency injection for ProductMatcher
  - Skips listings without titles
  - Timeout: 60s, Tries: 3, Backoff: 10s
  - Tags: `['product-matching', 'listing:{id}']`

- Created `MatchUnmatchedListings` command (`app/Console/Commands/MatchUnmatchedListings.php`)
  - Command signature: `crawler:match-unmatched`
  - Options: `--retailer=` (filter by slug), `--queue=default`, `--sync`, `--limit=0`, `--no-create`
  - Bulk matches all listings without existing ProductListingMatch records

- Updated `CrawlProductDetailsJob` to dispatch `MatchProductListingJob` after successfully extracting product details

**Confidence Score Breakdown:**
- 95-100%: Exact match (same brand + very similar title + same weight)
- 70-94%: Fuzzy match (similar title, may have brand/weight differences)
- <70%: No match, creates new Product (if enabled)

**Patterns established:**
- Services live in `app/Services/`
- Use dependency injection in jobs: `$job->handle(ProductMatcher $matcher)`
- Matching is idempotent - running multiple times produces same result
- ProductListingMatch has unique constraint on `product_listing_id`

**Gotchas:**
- Title normalization removes common words like "complete", "premium", "natural", "adult" - this improves matching but may affect similarity scores
- Weight extraction strips weight info from titles before similarity calculation
- Brand is required for exact matching, but fuzzy matching works without brands
- Quantity null values don't penalize the match (gives half points)

**Testing:**
- Unit tests: `tests/Unit/Services/ProductNormalizerTest.php` (39 tests)
- Unit tests: `tests/Unit/Services/ProductMatcherTest.php` (18 tests)
- Feature tests: `tests/Feature/Jobs/MatchProductListingJobTest.php` (8 tests)

### Amazon UK Crawler (f-0bbc3e) - Completed
- Created `AmazonCrawler` (`app/Crawler/Scrapers/AmazonCrawler.php`)
  - Extends `BaseCrawler`, registers all three extractors (URL, Details, Reviews)
  - Starting URLs: 8 URLs covering dog food (main, dry, wet), dog treats, puppy food, and popular brands
  - Request delay: 3000ms (3 seconds between requests - Amazon is aggressive with rate limiting)
  - Custom headers: Full browser-like headers including Sec-Fetch-* to avoid blocks
  - Notes: Requires BrightData Web Unlocker for reliable scraping due to anti-bot measures

- Created `AmazonProductListingUrlExtractor` (`app/Crawler/Extractors/Amazon/AmazonProductListingUrlExtractor.php`)
  - Extracts product URLs from search/category pages
  - Handles multiple URL patterns: `/dp/ASIN`, `/gp/product/ASIN`, `/gp/aw/d/ASIN` (mobile)
  - ASINs are 10-character alphanumeric identifiers (Amazon's unique product IDs)
  - Normalizes all product URLs to canonical form: `https://www.amazon.co.uk/dp/{ASIN}`
  - Deduplicates by ASIN to avoid crawling same product multiple times
  - Extracts category from search query or URL path

- Created `AmazonProductDetailsExtractor` (`app/Crawler/Extractors/Amazon/AmazonProductDetailsExtractor.php`)
  - Prioritizes JSON-LD structured data extraction (most reliable)
  - Falls back to Amazon-specific DOM selectors: `#productTitle`, `.priceToPay`, `#bylineInfo`
  - CAPTCHA/robot check detection - yields nothing if blocked page detected
  - Extracts Amazon-specific metadata: ASIN, Prime eligibility, Subscribe & Save price, deal badges
  - Handles multiple price formats: current price, RRP, S&S price
  - Weight parsing supports imperial units (lb, oz) common on Amazon
  - Image URL upgrading: converts thumbnail URLs to high-res versions (SL1500)
  - 90+ known pet food brands for fallback brand extraction

- Created `AmazonProductReviewsExtractor` (`app/Crawler/Extractors/Amazon/AmazonProductReviewsExtractor.php`)
  - Extracts reviews from product pages and dedicated review pages
  - Uses Amazon's `data-hook` attributes for reliable DOM selection
  - Parses star ratings from "X.X out of 5 stars" format
  - Extracts verified purchase status from badge
  - Handles UK date format: "Reviewed in the United Kingdom on 15 January 2024"
  - "One person found this helpful" → helpfulCount = 1
  - Static helper: `buildReviewsUrl(ASIN, page)` for review pagination

- Created `CrawlAmazonCommand` (`app/Console/Commands/CrawlAmazonCommand.php`)
  - Command signature: `crawler:amazon`
  - Options: `--queue=`, `--sync`
  - Warns about anti-bot measures at startup
  - Uses `useAdvancedAdapter: true` for BrightData

- Updated `RetailerSeeder` with Amazon UK entry:
  - crawler_class: `App\Crawler\Scrapers\AmazonCrawler`
  - rate_limit_ms: 3000 (3 seconds between requests)

**Patterns established:**
- Amazon-specific extractors in `app/Crawler/Extractors/Amazon/`
- ASIN is the external_id for Amazon products
- Use `data-hook` attributes for reliable DOM selection on Amazon
- Always check for CAPTCHA/robot pages before extraction
- JSON-LD offers can be single object or array - detect via `@type` or `price` keys

**Gotchas:**
- Amazon JSON-LD `offers` can be an object (single offer) or array (multiple offers)
- Detection: check for `@type` or `price` at top level to distinguish single vs array
- CAPTCHA pages have "captcha" in HTML or "Sorry" in title - must detect and skip
- Amazon image URLs have size codes like `_SX300_` that can be replaced with `_SL1500_` for larger images
- Price selectors vary by page layout - need multiple fallbacks
- Star ratings are in `<span class="a-icon-alt">` inside the star icon container
- Review dates include country: "Reviewed in the United Kingdom on..."

**Testing:**
- Unit tests: `tests/Unit/Crawler/Extractors/Amazon/AmazonProductListingUrlExtractorTest.php` (26 tests)
- Unit tests: `tests/Unit/Crawler/Extractors/Amazon/AmazonProductDetailsExtractorTest.php` (35 tests)
- Unit tests: `tests/Unit/Crawler/Extractors/Amazon/AmazonProductReviewsExtractorTest.php` (28 tests)

### Tesco Crawler (f-27bb0c) - Completed
- Created `TescoCrawler` (`app/Crawler/Scrapers/TescoCrawler.php`)
  - Extends `BaseCrawler`, registers all three extractors (URL, Details, Reviews)
  - Starting URLs: 5 URLs covering dog food (all, dry, wet), dog treats, and puppy food
  - Request delay: 2000ms (2 seconds between requests)
  - Custom headers: Accept-Language and Accept headers for UK content
  - Uses `useAdvancedAdapter: true` for reliable scraping

- Created `TescoProductListingUrlExtractor` (`app/Crawler/Extractors/Tesco/TescoProductListingUrlExtractor.php`)
  - Extracts product URLs from category pages
  - URL pattern: `/groceries/en-GB/products/{PRODUCTCODE}` where PRODUCTCODE is numeric (TPNs - Tesco Product Numbers)
  - Deduplicates product URLs
  - Extracts category from source URL path

- Created `TescoProductDetailsExtractor` (`app/Crawler/Extractors/Tesco/TescoProductDetailsExtractor.php`)
  - Prioritizes JSON-LD structured data extraction (most reliable)
  - Falls back to Tesco-specific DOM selectors: `[data-auto="product-title"]`, `.beans-price__text`
  - Extracts Clubcard price separately into metadata
  - Handles Tesco's JSON-LD offers format (single object or array)
  - 40+ known pet food brands for fallback brand extraction
  - Weight parsing supports kg, g, ml, l units
  - External ID is the TPN (Tesco Product Number) from URL

- Created `TescoProductReviewsExtractor` (`app/Crawler/Extractors/Tesco/TescoProductReviewsExtractor.php`)
  - Extracts reviews from JSON-LD structured data first
  - Falls back to DOM-based extraction if no JSON-LD reviews found
  - Handles various rating formats: data attributes, itemprop, star counts
  - Generates deterministic external IDs: `tesco-review-{md5(url+author+body)}-{index}`

- Created `CrawlTescoCommand` (`app/Console/Commands/CrawlTescoCommand.php`)
  - Command signature: `crawler:tesco`
  - Options: `--queue=`, `--sync`
  - Uses `useAdvancedAdapter: true` for BrightData

- Updated `CrawlProductReviewsCommand` extractor map:
  - Added `tesco` -> `TescoProductReviewsExtractor`

- `RetailerSeeder` already had Tesco entry:
  - crawler_class: `App\Crawler\Scrapers\TescoCrawler`
  - rate_limit_ms: 2000 (2 seconds between requests)

**Patterns established:**
- Tesco-specific extractors in `app/Crawler/Extractors/Tesco/`
- TPN (Tesco Product Number) is the external_id for Tesco products
- Clubcard price stored in metadata as `clubcard_price_pence`
- JSON-LD offers can be single object or array - detect via `@type` or `price` keys (same pattern as Amazon)

**Gotchas:**
- Tesco JSON-LD `offers` can be an object (single offer) or array (multiple offers)
- Detection: check for `@type` or `price` at top level to distinguish single vs array
- In PHP, JSON objects decode to associative arrays, so `is_array()` always returns true
- Must check for specific keys (`@type` or `price`) to detect single offer vs array of offers
- Clubcard prices are promotional and should be captured separately from regular prices
- Product IDs (TPNs) are always numeric

**Testing:**
- Unit tests: `tests/Unit/Crawler/Extractors/Tesco/TescoProductListingUrlExtractorTest.php` (16 tests)
- Unit tests: `tests/Unit/Crawler/Extractors/Tesco/TescoProductDetailsExtractorTest.php` (41 tests)
- Unit tests: `tests/Unit/Crawler/Extractors/Tesco/TescoProductReviewsExtractorTest.php` (32 tests)

### Asda Crawler (f-2a48cc) - Completed
- Created `AsdaCrawler` (`app/Crawler/Scrapers/AsdaCrawler.php`)
  - Extends `BaseCrawler`, registers all three extractors (URL, Details, Reviews)
  - Starting URLs: 7 URLs covering dog food (main, dry, wet, puppy) and dog treats (main, chews, biscuits)
  - Request delay: 2000ms (2 seconds between requests)
  - Custom headers: Full browser-like headers including Sec-Fetch-* to mimic browser behavior
  - Base URL: `groceries.asda.com` (separate subdomain from main asda.com)

- Created `AsdaProductListingUrlExtractor` (`app/Crawler/Extractors/Asda/AsdaProductListingUrlExtractor.php`)
  - Extracts product URLs from category/aisle pages
  - URL pattern: `/product/[product-name]/[SKU-ID]` or `/product/[SKU-ID]`
  - SKU IDs are numeric identifiers
  - Handles category pages (`/aisle/`), shelf pages (`/shelf/`), search pages (`/search/`), and super-department pages
  - Also extracts product IDs from inline JavaScript/JSON data (Asda uses dynamic loading)
  - Normalizes all URLs to absolute form with `groceries.asda.com`
  - Extracts category from URL path

- Created `AsdaProductDetailsExtractor` (`app/Crawler/Extractors/Asda/AsdaProductDetailsExtractor.php`)
  - Prioritizes JSON-LD structured data extraction (most reliable)
  - Falls back to Asda-specific DOM selectors: `[data-auto-id="pdp-product-title"]`, `[data-auto-id="pdp-price"]`
  - Extracts Asda Rewards price and Rollback (promotional) price into metadata
  - Handles price-per-unit extraction
  - Handles JSON-LD offers format (single object or array)
  - 50+ known pet food brands including ASDA own brands (Extra Special, Smart Price)
  - Weight parsing supports kg, g, ml, l, lb, oz units
  - External ID is the SKU ID from URL

- Created `AsdaProductReviewsExtractor` (`app/Crawler/Extractors/Asda/AsdaProductReviewsExtractor.php`)
  - Extracts reviews from JSON-LD structured data first
  - Falls back to DOM-based extraction if no JSON-LD reviews found
  - Handles various rating formats: data attributes, aria-label, star counts
  - Generates deterministic external IDs: `asda-review-{md5(body+author)}-{index}`
  - Static helper: `buildReviewsUrl(productId, page)` for review pagination

- Created `CrawlAsdaCommand` (`app/Console/Commands/CrawlAsdaCommand.php`)
  - Command signature: `crawler:asda`
  - Options: `--queue=`, `--sync`

- Updated `RetailerSeeder` with Asda entry:
  - base_url: `https://groceries.asda.com` (not www.asda.com)
  - crawler_class: `App\Crawler\Scrapers\AsdaCrawler`
  - rate_limit_ms: 2000 (2 seconds between requests)

**Patterns established:**
- Asda-specific extractors in `app/Crawler/Extractors/Asda/`
- SKU ID is the external_id for Asda products
- Asda Rewards price stored in metadata as `asda_rewards_price`
- Rollback (promotional) price stored in metadata as `rollback_price`
- Asda uses `data-auto-id` attributes for reliable DOM selection

**Gotchas:**
- Asda Groceries is on `groceries.asda.com`, not `www.asda.com`
- Asda may use infinite scroll/AJAX for category pages - product IDs can be in inline scripts
- Asda Rewards prices may differ from standard prices - capture both
- Rollback prices are promotional prices that need capturing
- Product weights are often in the title, need parsing
- JSON-LD offers can be single object or array (same pattern as Amazon/Tesco)

**File locations:**
- `app/Crawler/Scrapers/AsdaCrawler.php`
- `app/Crawler/Extractors/Asda/AsdaProductListingUrlExtractor.php`
- `app/Crawler/Extractors/Asda/AsdaProductDetailsExtractor.php`
- `app/Crawler/Extractors/Asda/AsdaProductReviewsExtractor.php`
- `app/Console/Commands/CrawlAsdaCommand.php`

### Sainsbury's Crawler (f-1e7c5f) - Completed
- Created `SainsburysCrawler` (`app/Crawler/Scrapers/SainsburysCrawler.php`)
  - Extends `BaseCrawler`, registers all three extractors (URL, Details, Reviews)
  - Starting URLs: 5 URLs covering dog food (all, dry, wet), dog treats, and puppy food
  - Request delay: 2000ms (2 seconds between requests)
  - Custom headers: Accept-Language and Accept headers for UK content
  - Base URL: `www.sainsburys.co.uk` with `/gol-ui/` path for groceries

- Updated `SainsburysProductListingUrlExtractor` (`app/Crawler/Extractors/Sainsburys/SainsburysProductListingUrlExtractor.php`)
  - Extracts product URLs from category pages
  - URL pattern: `/gol-ui/product/[product-name]--[product-code]` where product code is numeric
  - Also handles alternative patterns: `/product/[name]-[code]`, `/shop/gb/groceries/[category]/[name]--[code]`
  - Extracts product IDs from inline JSON data for dynamic pages
  - Added `extractProductCodeFromUrl()` method for external ID extraction
  - Extracts category from URL path

- Used existing `SainsburysProductDetailsExtractor` (`app/Crawler/Extractors/Sainsburys/SainsburysProductDetailsExtractor.php`)
  - Prioritizes JSON-LD structured data extraction (most reliable)
  - Falls back to Sainsbury's-specific DOM selectors: `[data-test-id="pd-product-title"]`, `[data-test-id="pd-retail-price"]`
  - Extracts Nectar price (loyalty card price) into metadata as `nectar_price_pence`
  - Extracts multi-buy offers with text, quantity, and price
  - Handles JSON-LD offers format (single object or array)
  - 40+ known pet food brands including Sainsbury's own brands (Taste the Difference, Basics)
  - Weight parsing supports kg, g, ml, l, lb, oz units
  - External ID is the product code from URL (after `--`)

- Created `SainsburysProductReviewsExtractor` (`app/Crawler/Extractors/Sainsburys/SainsburysProductReviewsExtractor.php`)
  - Extracts reviews from JSON-LD structured data first
  - Falls back to DOM-based extraction if no JSON-LD reviews found
  - Supports Bazaarvoice review widget selectors (`.bv-content-item`, `.bv-content-review`)
  - Handles various rating formats: data attributes, aria-label, star counts, percentage widths
  - Generates deterministic external IDs: `sainsburys-review-{md5(body+author)}-{index}`
  - Static helper: `buildReviewsUrl(productId, page)` for review pagination

- Created `CrawlSainsburysCommand` (`app/Console/Commands/CrawlSainsburysCommand.php`)
  - Command signature: `crawler:sainsburys`
  - Options: `--queue=`, `--sync`

- Updated `RetailerSeeder` with Sainsbury's crawler:
  - crawler_class: `App\Crawler\Scrapers\SainsburysCrawler`
  - rate_limit_ms: 2000 (2 seconds between requests)

- Updated `CrawlProductReviewsCommand` extractor map:
  - Added `sainsburys` -> `SainsburysProductReviewsExtractor`

**Patterns established:**
- Sainsbury's-specific extractors in `app/Crawler/Extractors/Sainsburys/`
- Product code (after `--` in URL) is the external_id for Sainsbury's products
- Nectar price stored in metadata as `nectar_price_pence`
- Multi-buy offers stored in metadata with text, quantity, and price fields
- Sainsbury's uses `data-test-id` and `data-testid` attributes for reliable DOM selection
- May use Bazaarvoice for reviews (`.bv-*` selectors)

**Gotchas:**
- Sainsbury's groceries section uses `/gol-ui/` path prefix (Groceries Online UI)
- Product codes are numeric and appear after `--` in URLs (e.g., `pedigree-dog-food--12345`)
- JSON-LD offers can be single object or array (same pattern as Amazon/Tesco/Asda)
- Nectar prices are loyalty card promotional prices - capture separately from regular prices
- Reviews may be loaded via Bazaarvoice widget which has its own DOM structure
- May require postcode cookie for availability (default UK postcode should work)

**File locations:**
- `app/Crawler/Scrapers/SainsburysCrawler.php`
- `app/Crawler/Extractors/Sainsburys/SainsburysProductListingUrlExtractor.php`
- `app/Crawler/Extractors/Sainsburys/SainsburysProductDetailsExtractor.php`
- `app/Crawler/Extractors/Sainsburys/SainsburysProductReviewsExtractor.php`
- `app/Console/Commands/CrawlSainsburysCommand.php`

### Just for Pets Crawler (f-e43361) - Completed
- Used existing `JustForPetsCrawler` (`app/Crawler/Scrapers/JustForPetsCrawler.php`)
  - Extends `BaseCrawler`, registers all three extractors (URL, Details, Reviews)
  - Starting URLs: 4 URLs covering dog food (all, dry, wet) and dog treats
  - Request delay: 1500ms (1.5 seconds between requests - simpler site, less aggressive)
  - Base URL: `www.justforpetsonline.co.uk`

- Used existing `JFPProductListingUrlExtractor` (`app/Crawler/Extractors/JustForPets/JFPProductListingUrlExtractor.php`)
  - Extracts product URLs from category pages
  - Supports multiple URL patterns: `/product/slug-id`, `/products/slug-id`, `/p/id`, `-p-id.html`, `slug-id.html`
  - Extracts external ID from URL patterns
  - Extracts category from source URL (dog, cat, puppy, etc.)
  - Deduplicates URLs

- Used existing `JFPProductDetailsExtractor` (`app/Crawler/Extractors/JustForPets/JFPProductDetailsExtractor.php`)
  - Prioritizes JSON-LD structured data extraction (most reliable)
  - Falls back to DOM selectors if JSON-LD unavailable
  - Supports WooCommerce-style selectors (`.woocommerce-*`)
  - Extracts: title, description, brand, price, images, ingredients, stock status, external ID
  - 100+ known pet food brands for fallback brand extraction
  - Weight parsing supports kg, g, ml, l, lb, oz units
  - External ID from SKU in JSON-LD or URL patterns

- Used existing `JFPProductReviewsExtractor` (`app/Crawler/Extractors/JustForPets/JFPProductReviewsExtractor.php`)
  - Extracts reviews from JSON-LD structured data first
  - Falls back to DOM-based extraction if no JSON-LD reviews found
  - Supports WooCommerce review selectors (`.woocommerce-Reviews`, `.review`)
  - Supports star rating extraction from multiple methods: data attributes, percentage widths, star counts
  - Generates deterministic external IDs: `jfp-review-{md5(author+body)}-{index}`

- Used existing `CrawlJustForPetsCommand` (`app/Console/Commands/CrawlJustForPetsCommand.php`)
  - Command signature: `crawler:just-for-pets`
  - Options: `--queue=`, `--sync`

- Updated `CrawlProductReviewsCommand` extractor map:
  - Added `just-for-pets` -> `JFPProductReviewsExtractor`

- RetailerSeeder already had Just for Pets entry:
  - base_url: `https://www.justforpetsonline.co.uk`
  - crawler_class: `App\Crawler\Scrapers\JustForPetsCrawler`
  - rate_limit_ms: 1500 (1.5 seconds between requests)

**Patterns established:**
- JFP-specific extractors in `app/Crawler/Extractors/JustForPets/`
- External ID sourced from SKU in JSON-LD, or extracted from URL patterns
- Standard WooCommerce-style selectors work well for simpler e-commerce sites
- Rating extraction via percentage width (WooCommerce style): `width: 80%` → 4 stars

**Gotchas:**
- Multiple URL patterns exist: `/product/`, `/products/`, `/p/`, `-p-`, `slug-id.html`
- Category extraction from URL path matches first animal name (dog, cat, puppy, kitten)
- Pest test name collisions: avoid `/p/` and `-p-` in test names as they generate similar method names
- JSON-LD offers can be single object or array (same pattern as other retailers)

**Testing:**
- Unit tests: `tests/Unit/Crawler/Extractors/JustForPets/JFPProductListingUrlExtractorTest.php` (27 tests)
- Unit tests: `tests/Unit/Crawler/Extractors/JustForPets/JFPProductDetailsExtractorTest.php` (54 tests)
- Unit tests: `tests/Unit/Crawler/Extractors/JustForPets/JFPProductReviewsExtractorTest.php` (40 tests)

**File locations:**
- `app/Crawler/Scrapers/JustForPetsCrawler.php`
- `app/Crawler/Extractors/JustForPets/JFPProductListingUrlExtractor.php`
- `app/Crawler/Extractors/JustForPets/JFPProductDetailsExtractor.php`
- `app/Crawler/Extractors/JustForPets/JFPProductReviewsExtractor.php`
- `app/Console/Commands/CrawlJustForPetsCommand.php`

### Morrisons Crawler (f-d2850a) - Completed
- Created `MorrisonsCrawler` (`app/Crawler/Scrapers/MorrisonsCrawler.php`)
  - Extends `BaseCrawler`, registers all three extractors (URL, Details, Reviews)
  - Starting URLs: 5 URLs covering dog food (main, dry, wet), dog treats, and puppy food
  - Request delay: 2000ms (2 seconds between requests)
  - Custom headers: Full browser-like headers including Sec-Fetch-* to mimic browser behavior
  - Base URL: `groceries.morrisons.com` (separate subdomain from main morrisons.com)

- Created `MorrisonsProductListingUrlExtractor` (`app/Crawler/Extractors/Morrisons/MorrisonsProductListingUrlExtractor.php`)
  - Extracts product URLs from category/browse pages
  - URL pattern: `/products/[product-slug]/[SKU]` where SKU can be numeric or alphanumeric
  - Handles browse pages (`/browse/pet/`), search pages, category pages
  - Normalizes all URLs to absolute form with `groceries.morrisons.com`
  - Extracts category from URL path

- Created `MorrisonsProductDetailsExtractor` (`app/Crawler/Extractors/Morrisons/MorrisonsProductDetailsExtractor.php`)
  - Prioritizes JSON-LD structured data extraction (most reliable)
  - Falls back to Morrisons-specific DOM selectors: `[data-test="product-title"]`, `[data-test="product-price"]`
  - Extracts Price Dropped promotional price into metadata as `price_dropped_pence`
  - Extracts My Morrisons member price into metadata as `my_morrisons_price_pence`
  - Handles JSON-LD offers format (single object or array)
  - 50+ known pet food brands including Morrisons own brands (The Best, Savers)
  - Weight parsing supports kg, g, ml, l, lb, oz units
  - External ID is the SKU from URL (last segment after product slug)

- Created `MorrisonsProductReviewsExtractor` (`app/Crawler/Extractors/Morrisons/MorrisonsProductReviewsExtractor.php`)
  - Extracts reviews from JSON-LD structured data first
  - Falls back to DOM-based extraction if no JSON-LD reviews found
  - Handles various rating formats: data attributes, itemprop, star counts
  - Generates deterministic external IDs: `morrisons-review-{md5(url+author+body)}-{index}`

- Created `CrawlMorrisonsCommand` (`app/Console/Commands/CrawlMorrisonsCommand.php`)
  - Command signature: `crawler:morrisons`
  - Options: `--queue=`, `--sync`
  - Uses `useAdvancedAdapter: true` for reliable scraping (React frontend)

- Updated `RetailerSeeder` with Morrisons entry:
  - base_url: `https://groceries.morrisons.com` (not www.morrisons.com)
  - crawler_class: `App\Crawler\Scrapers\MorrisonsCrawler`
  - rate_limit_ms: 2000 (2 seconds between requests)

- Updated `CrawlProductReviewsCommand` extractor map:
  - Added `morrisons` -> `MorrisonsProductReviewsExtractor`

**Patterns established:**
- Morrisons-specific extractors in `app/Crawler/Extractors/Morrisons/`
- SKU is the external_id for Morrisons products (extracted from URL)
- Price Dropped price stored in metadata as `price_dropped_pence`
- My Morrisons member price stored in metadata as `my_morrisons_price_pence`
- Morrisons uses `data-test` attributes for reliable DOM selection

**Gotchas:**
- Morrisons Groceries is on `groceries.morrisons.com`, not `www.morrisons.com`
- React-based frontend may require JavaScript rendering for full content
- May require postcode selection for store availability
- Price Dropped items are promotional prices that need capturing
- My Morrisons offers show loyalty member prices - capture separately
- JSON-LD offers can be single object or array (same pattern as other retailers)
- SKUs can be alphanumeric, not just numeric

**Testing:**
- Unit tests: `tests/Unit/Crawler/Extractors/Morrisons/MorrisonsProductListingUrlExtractorTest.php` (18 tests)
- Unit tests: `tests/Unit/Crawler/Extractors/Morrisons/MorrisonsProductDetailsExtractorTest.php` (35 tests)
- Unit tests: `tests/Unit/Crawler/Extractors/Morrisons/MorrisonsProductReviewsExtractorTest.php` (31 tests)

**File locations:**
- `app/Crawler/Scrapers/MorrisonsCrawler.php`
- `app/Crawler/Extractors/Morrisons/MorrisonsProductListingUrlExtractor.php`
- `app/Crawler/Extractors/Morrisons/MorrisonsProductDetailsExtractor.php`
- `app/Crawler/Extractors/Morrisons/MorrisonsProductReviewsExtractor.php`
- `app/Console/Commands/CrawlMorrisonsCommand.php`

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->