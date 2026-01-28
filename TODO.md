# Fang - Product Aggregation System TODO

## Phase 1: Database Foundation

### Models & Migrations
- [ ] Create `Retailer` model and migration
  - [ ] Fields: name, slug, base_url, crawler_class, is_active, rate_limit_ms, last_crawled_at
  - [ ] Factory and seeder with initial retailers (B&M, Pets at Home, etc.)
- [ ] Create `ProductListing` model and migration
  - [ ] Fields: retailer_id, external_id, url, title, description, price_pence, original_price_pence, currency, weight_grams, quantity, brand, category, images (JSON), ingredients, nutritional_info (JSON), in_stock, stock_quantity, last_scraped_at
  - [ ] Indexes on retailer_id, external_id, url, brand, category
  - [ ] Factory for testing
- [ ] Create `ProductListingPrice` model and migration
  - [ ] Fields: product_listing_id, price_pence, original_price_pence, currency, recorded_at
  - [ ] Index on product_listing_id + recorded_at
- [ ] Create `ProductListingReview` model and migration
  - [ ] Fields: product_listing_id, external_id, author, rating, title, body, verified_purchase, review_date, helpful_count, metadata (JSON)
  - [ ] Unique constraint on product_listing_id + external_id
- [ ] Create `Product` model and migration (canonical products)
  - [ ] Fields: name, slug, brand, description, category, subcategory, weight_grams, quantity, primary_image, average_price_pence, lowest_price_pence, is_verified, metadata (JSON)
  - [ ] Full-text index on name, brand, description
- [ ] Create `ProductListingMatch` model and migration
  - [ ] Fields: product_id, product_listing_id, confidence_score, match_type (enum: exact, fuzzy, barcode, manual), matched_at, verified_by, verified_at
  - [ ] Unique constraint on product_listing_id

### Relationships & Eloquent Setup
- [ ] Define `Retailer` relationships (hasMany ProductListings)
- [ ] Define `ProductListing` relationships (belongsTo Retailer, hasMany Prices, hasMany Reviews, belongsToMany Products through matches)
- [ ] Define `Product` relationships (belongsToMany ProductListings through matches)
- [ ] Add query scopes: `inStock()`, `onSale()`, `byRetailer()`, `byBrand()`, `byCategory()`
- [ ] Add casts for JSON fields, dates, enums

---

## Phase 2: Event Sourcing Infrastructure

### Projectors
- [ ] Create `CrawlStatisticsProjector`
  - [ ] Track crawls per retailer per day
  - [ ] Track success/failure rates
  - [ ] Track products discovered count
- [ ] Create `ProductListingProjector`
  - [ ] Listen to `ProductListingDiscovered` events
  - [ ] Create or update `ProductListing` records
  - [ ] Queue detail scrape jobs for new listings

### Reactors
- [ ] Create `DispatchProductDetailsCrawlReactor`
  - [ ] When listing URL discovered, dispatch job to scrape full details
  - [ ] Debounce to avoid duplicate jobs
- [ ] Create `RecordPriceChangeReactor`
  - [ ] When price changes detected, create `ProductListingPrice` record
  - [ ] Flag unusual price changes (>50%) for review
- [ ] Create `NotifyCrawlFailureReactor`
  - [ ] Send alerts on repeated failures
  - [ ] Implement circuit breaker pattern
- [ ] Create `UpdateRetailerHealthReactor`
  - [ ] Track success rates per retailer
  - [ ] Auto-disable retailers with consistent failures

### Additional Events
- [ ] Create `ProductDetailsScraped` event
- [ ] Create `ProductPriceChanged` event
- [ ] Create `ProductOutOfStock` event
- [ ] Create `ProductBackInStock` event
- [ ] Create `ReviewsScraped` event

### Aggregate Enhancements
- [ ] Create `ProductListingAggregate` for tracking individual listing lifecycle
- [ ] Add snapshot support for large aggregates

---

## Phase 3: DTOs & Data Extraction

### New DTOs
- [ ] Create `ProductDetails` DTO
  - [ ] title, description, brand, price, originalPrice, currency, weight, quantity, images[], ingredients, nutritionalInfo, inStock, stockQuantity, metadata
- [ ] Create `ProductPrice` DTO
  - [ ] price, originalPrice, currency, hasDiscount, discountPercentage
- [ ] Create `ProductReview` DTO
  - [ ] externalId, author, rating, title, body, verifiedPurchase, reviewDate, helpfulCount
- [ ] Create `ProductImage` DTO
  - [ ] url, altText, isPrimary, width, height
- [ ] Create `ProductIngredients` DTO
  - [ ] ingredients[], allergens[], additives[]

### Extractor Contracts
- [ ] Create `ProductDetailsExtractorInterface`
- [ ] Create `ProductReviewsExtractorInterface`
- [ ] Create `PaginationExtractorInterface` (for handling multi-page results)

---

## Phase 4: B&M Retailer Completion

### B&M Extractors
- [ ] Create `BMProductDetailsExtractor`
  - [ ] Extract title, description, price from product pages
  - [ ] Extract images (main + gallery)
  - [ ] Extract weight/quantity from title parsing
  - [ ] Extract brand
  - [ ] Extract stock status
- [ ] Create `BMProductReviewsExtractor`
  - [ ] Extract reviews if B&M has them
  - [ ] Handle pagination if needed
- [ ] Update `BMCrawler` to register all extractors

### B&M Jobs
- [ ] Create `CrawlProductDetailsJob`
  - [ ] Fetch product page, run detail extractors
  - [ ] Update ProductListing record
  - [ ] Record price history if changed
- [ ] Create `CrawlProductReviewsJob`
  - [ ] Fetch reviews for a product
  - [ ] Upsert reviews (avoid duplicates)

### B&M Testing
- [ ] Create fixture HTML files for B&M pages
- [ ] Unit tests for BMProductDetailsExtractor
- [ ] Unit tests for BMProductReviewsExtractor
- [ ] Integration test for full B&M crawl flow

---

## Phase 5: Additional Retailers

### Pets at Home
- [ ] Create `PetsAtHomeCrawler`
- [ ] Create `PAHProductListingUrlExtractor`
- [ ] Create `PAHProductDetailsExtractor`
- [ ] Create `PAHProductReviewsExtractor`
- [ ] Add starting URLs for dog food categories
- [ ] Create artisan command `crawler:pah`
- [ ] Unit tests for all extractors

### Amazon UK
- [ ] Research Amazon scraping approach (likely need ScrapingBee/Zyte)
- [ ] Create `AmazonUKCrawler`
- [ ] Create `AmazonProductListingUrlExtractor`
- [ ] Create `AmazonProductDetailsExtractor`
- [ ] Create `AmazonProductReviewsExtractor`
- [ ] Handle Amazon's anti-bot measures
- [ ] Unit tests

### Tesco
- [ ] Create `TescoCrawler`
- [ ] Create Tesco extractors
- [ ] Handle Tesco's JS-heavy pages
- [ ] Unit tests

### Asda
- [ ] Create `AsdaCrawler`
- [ ] Create Asda extractors
- [ ] Unit tests

### Sainsbury's
- [ ] Create `SainsburysCrawler`
- [ ] Create Sainsbury's extractors
- [ ] Unit tests

### Morrisons
- [ ] Create `MorrisonsCrawler`
- [ ] Create Morrisons extractors
- [ ] Unit tests

### Just for Pets
- [ ] Create `JustForPetsCrawler`
- [ ] Create Just for Pets extractors
- [ ] Unit tests

---

## Phase 6: Product Matching System

### Matching Algorithm
- [ ] Create `ProductMatchingService`
  - [ ] Exact match: brand + normalized name + weight/quantity
  - [ ] Fuzzy match: Levenshtein distance on names
  - [ ] Configurable confidence thresholds
- [ ] Create `ProductNameNormalizer`
  - [ ] Remove common words (the, a, an)
  - [ ] Normalize weight formats (1kg, 1000g, 1 kilogram)
  - [ ] Normalize brand name variations
- [ ] Create `WeightParser`
  - [ ] Parse weights from strings ("1.5kg", "500g", "2 x 400g")
  - [ ] Convert to standard unit (grams)
- [ ] Create `BrandMatcher`
  - [ ] Brand name aliases/variations
  - [ ] Unknown brand handling

### Matching Jobs
- [ ] Create `MatchProductListingJob`
  - [ ] Run matching algorithm on new/updated listings
  - [ ] Create ProductListingMatch records
  - [ ] Flag low-confidence matches for review
- [ ] Create `CreateCanonicalProductJob`
  - [ ] When no match found, create new canonical Product
  - [ ] Set initial data from first listing

### Matching Commands
- [ ] Create `products:match` command
  - [ ] Run matching on unmatched listings
  - [ ] Options for dry-run, retailer filter, confidence threshold
- [ ] Create `products:merge` command
  - [ ] Merge duplicate canonical products
  - [ ] Update all related matches

### Matching Tests
- [ ] Unit tests for ProductNameNormalizer
- [ ] Unit tests for WeightParser
- [ ] Unit tests for ProductMatchingService
- [ ] Integration tests for matching flow

---

## Phase 7: Scheduling & Automation

### Scheduled Tasks
- [ ] Schedule category crawls (daily, 2-5 AM)
  - [ ] Stagger by retailer to spread load
- [ ] Schedule product detail updates (daily, throughout day)
  - [ ] Prioritize products not updated recently
  - [ ] Prioritize products with price alerts
- [ ] Schedule review crawls (weekly)
- [ ] Schedule product matching (hourly for new listings)
- [ ] Schedule statistics aggregation (daily)

### Rate Limiting
- [ ] Implement per-retailer rate limiting
- [ ] Create `RateLimiter` middleware for jobs
- [ ] Track requests per minute per retailer
- [ ] Auto-throttle when approaching limits

### Queue Configuration
- [ ] Set up Redis queues for production
- [ ] Create separate queues: `crawl-discovery`, `crawl-details`, `crawl-reviews`, `matching`
- [ ] Configure Horizon for queue monitoring
- [ ] Set up queue workers per queue type

### Health Monitoring
- [ ] Create `RetailerHealthCheck` command
  - [ ] Test connectivity to each retailer
  - [ ] Verify extractors still work (HTML structure changes)
- [ ] Schedule health checks (every 6 hours)
- [ ] Auto-disable failing retailers

---

## Phase 8: API & Frontend

### API Endpoints
- [ ] Create API routes (versioned: `/api/v1/`)
- [ ] `GET /products` - List canonical products with filters
- [ ] `GET /products/{slug}` - Single product with all listings
- [ ] `GET /products/{slug}/prices` - Price history across retailers
- [ ] `GET /products/{slug}/reviews` - Aggregated reviews
- [ ] `GET /retailers` - List active retailers
- [ ] `GET /retailers/{slug}/products` - Products from specific retailer
- [ ] `GET /search` - Full-text product search

### API Resources
- [ ] Create `ProductResource`
- [ ] Create `ProductListingResource`
- [ ] Create `ProductPriceResource`
- [ ] Create `ProductReviewResource`
- [ ] Create `RetailerResource`

### Frontend Pages (Inertia/Vue)
- [ ] Dashboard with crawl statistics
- [ ] Products list with search/filters
- [ ] Product detail page showing all retailers
- [ ] Price comparison chart (price over time)
- [ ] Retailer management page
- [ ] Crawl history/logs page
- [ ] Manual product matching interface
- [ ] Settings page

### Admin Features
- [ ] Manual crawl trigger UI
- [ ] Product merge/split UI
- [ ] Match verification UI
- [ ] Retailer enable/disable
- [ ] View failed jobs

---

## Phase 9: Monitoring & Alerting

### Logging
- [ ] Structured logging for all crawl operations
- [ ] Log to separate channels: `crawl`, `matching`, `errors`
- [ ] Set up log rotation

### Metrics
- [ ] Track crawl success/failure rates
- [ ] Track products discovered per crawl
- [ ] Track price changes detected
- [ ] Track matching confidence distribution
- [ ] Track queue depths and processing times

### Alerting
- [ ] Alert on crawler failures (>3 consecutive failures)
- [ ] Alert on retailer going unhealthy
- [ ] Alert on unusual price changes
- [ ] Alert on queue backlog
- [ ] Alert on disk space (stored events table growth)

### Dashboards
- [ ] Grafana dashboard for crawl metrics (or Laravel Pulse)
- [ ] Queue monitoring (Horizon)
- [ ] Error tracking (Sentry/Bugsnag integration)

---

## Phase 10: Testing & Quality

### Unit Tests
- [ ] All extractor classes
- [ ] All DTOs
- [ ] ProductMatchingService
- [ ] WeightParser
- [ ] ProductNameNormalizer
- [ ] UserAgentRotator (exists)
- [ ] All adapters (exists)

### Feature Tests
- [ ] Full crawl flow per retailer
- [ ] Product matching flow
- [ ] Price history recording
- [ ] API endpoints
- [ ] Event sourcing projectors/reactors

### Browser Tests (Pest v4)
- [ ] Admin dashboard functionality
- [ ] Product search and filtering
- [ ] Product detail page
- [ ] Manual matching interface

### Fixtures
- [ ] HTML fixtures for each retailer's pages
- [ ] Mock HTTP responses for adapter tests
- [ ] Seed data for testing

---

## Phase 11: Performance & Scaling

### Database Optimization
- [ ] Add appropriate indexes based on query patterns
- [ ] Partition stored_events table by date
- [ ] Archive old price history (>1 year)
- [ ] Optimize full-text search indexes

### Caching
- [ ] Cache canonical product data
- [ ] Cache retailer configurations
- [ ] Cache price comparison results
- [ ] Invalidate cache on updates

### Queue Optimization
- [ ] Batch similar jobs together
- [ ] Implement job chaining for crawl workflows
- [ ] Use job middleware for rate limiting
- [ ] Optimize job payloads (avoid serializing large objects)

### Horizontal Scaling
- [ ] Ensure all jobs are idempotent
- [ ] Use database locks for critical sections
- [ ] Configure for Laravel Cloud deployment
- [ ] Set up multiple queue workers

---

## Phase 12: Future Enhancements

### Advanced Features
- [ ] Barcode/EAN lookup for exact matching
- [ ] Image-based product matching (ML)
- [ ] Price prediction/trending
- [ ] Price drop alerts/notifications
- [ ] User accounts and wishlists
- [ ] Browser extension for price checking

### Additional Product Categories
- [ ] Cat food & treats
- [ ] Other pet food (birds, fish, small animals)
- [ ] Pet accessories
- [ ] Pet healthcare products

### Additional Data Sources
- [ ] Manufacturer websites for product info
- [ ] Price comparison APIs
- [ ] Review aggregation services

### International Expansion
- [ ] EU retailers
- [ ] US retailers (Chewy, PetSmart, etc.)
- [ ] Multi-currency support

---

## Current Progress

### Completed
- [x] Project setup (Laravel 12, Vue 3, Tailwind 4)
- [x] Event sourcing infrastructure (Spatie package)
- [x] Base crawler architecture
- [x] HTTP adapter pattern with Guzzle
- [x] Proxy support (BrightData)
- [x] User agent rotation
- [x] B&M product listing URL extractor
- [x] CrawlAggregate with events
- [x] CrawlProductListingsJob
- [x] Basic artisan command for B&M

### In Progress
- [ ] Database models and migrations
- [ ] Event sourcing projectors

### Next Up
- [ ] Create Retailer, ProductListing models
- [ ] Create ProductListingProjector
- [ ] Complete B&M product details extractor
