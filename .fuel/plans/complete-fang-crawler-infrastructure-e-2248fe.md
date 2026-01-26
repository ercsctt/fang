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

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->