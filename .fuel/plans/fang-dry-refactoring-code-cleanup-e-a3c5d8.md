# Epic: Fang DRY Refactoring & Code Cleanup (e-a3c5d8)

## Plan

Refactor the Fang codebase to follow DRY principles - consolidate duplicate code across crawlers, extractors, commands, and tests

<!-- Add implementation plan here -->

## Implementation Notes

### ✅ NormalizesUrls Trait (Task f-d4498b)
**Completed**: Created `app/Crawler/Extractors/Concerns/NormalizesUrls.php` trait to consolidate duplicate URL normalization logic.

**Key Decisions**:
- Used the most comprehensive version of normalizeUrl() that handles all URL types:
  - Absolute URLs (http/https) - returned as-is
  - Protocol-relative URLs (//) - prefixed with scheme from baseUrl
  - Absolute paths (/) - combined with scheme and host from baseUrl
  - Relative paths - combined with full base path from baseUrl
- Made the method `protected` so it can be used by extractors and overridden if needed
- All 11 ProductListingUrlExtractor classes now use this trait

**Extractors Updated**:
1. Amazon - uses trait, simplified normalizePageUrl()
2. Asda - uses trait, refactored normalizeProductUrl() to use it
3. Sainsburys - uses trait, removed duplicate normalizeUrl()
4. Ocado - uses trait, removed duplicate normalizeUrl()
5. PetsAtHome - uses trait, removed duplicate normalizeUrl()
6. JustForPets - uses trait, removed duplicate normalizeUrl()
7. Tesco - uses trait, removed duplicate normalizeUrl()
8. Waitrose - uses trait, removed duplicate normalizeUrl()
9. Morrisons - uses trait, removed duplicate normalizeUrl()
10. Zooplus - uses trait, removed duplicate normalizeUrl()
11. BM - uses trait, removed duplicate normalizeUrl()

**Pattern to Follow**:
- For extractors with ExtractsPagination trait: Implement normalizePageUrl() as `return $this->normalizeUrl($href, $baseUrl);`
- For extractors without ExtractsPagination: Just use the trait and call normalizeUrl() directly

**Gotchas**:
- The ExtractsPagination trait requires a `normalizePageUrl()` method - don't remove this from extractors that use pagination
- Some extractors had default hosts in their normalizeUrl() implementations - the trait uses parse_url() on baseUrl instead, which is more flexible

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->

### ✅ Unified Crawler Command (Task f-237d34)
**Completed**: Consolidated 8 individual crawler commands into a single `crawler:run` command.

**Files Deleted**:
- `app/Console/Commands/CrawlAmazonCommand.php`
- `app/Console/Commands/CrawlTescoCommand.php`
- `app/Console/Commands/CrawlAsdaCommand.php`
- `app/Console/Commands/CrawlJustForPetsCommand.php`
- `app/Console/Commands/CrawlSainsburysCommand.php`
- `app/Console/Commands/CrawlMorrisonsCommand.php`
- `app/Console/Commands/CrawlBMProductListings.php`
- `app/Console/Commands/CrawlPetsAtHomeProductListings.php`

**Files Created**:
- `app/Console/Commands/CrawlRetailerCommand.php` - Unified crawler command
- `tests/Feature/CrawlRetailerCommandTest.php` - Comprehensive test suite

**Key Decisions**:
- Command signature: `crawler:run {retailer?*} {--all} {--queue=} {--sync}`
- Retailers are identified by their slug (e.g., `tesco`, `bm`, `amazon-uk`)
- Multiple retailers can be specified: `php artisan crawler:run tesco asda bm`
- `--all` flag crawls all active, non-paused retailers with valid crawler_class
- Skipped retailers show warnings (inactive, paused, missing crawler_class)
- Lists available retailers when no match found for user convenience
- Updates `last_crawled_at` timestamp on retailer after crawl jobs dispatched

**Usage Examples**:
```bash
php artisan crawler:run tesco           # Crawl single retailer
php artisan crawler:run tesco asda      # Crawl multiple retailers
php artisan crawler:run --all           # Crawl all active retailers
php artisan crawler:run tesco --sync    # Run synchronously
php artisan crawler:run tesco --queue=high  # Dispatch to specific queue
```

**Note**: The existing `crawler:dispatch-all` command remains for backward compatibility and has slightly different behavior (delay between retailers, default queue). Consider deprecating in favor of `crawler:run --all` in future.

### ✅ Brand Configuration Consolidation (Task f-0cc527)
**Completed**: Moved KNOWN_BRANDS constant from 10 ProductDetailsExtractor classes to centralized configuration.

**Files Created**:
- `config/brands.php` - Centralized brand configuration with:
  - `known_brands` array: 115+ core pet food brands used across all retailers
  - `retailer_specific` array: Own-brand labels for each retailer (Amazon, Asda, Morrisons, Sainsbury's, Waitrose, Zooplus)

**Extractors Updated** (10 total):
1. Amazon - added getKnownBrands() method
2. Asda - added getKnownBrands() method
3. JustForPets - added getKnownBrands() method
4. Morrisons - added getKnownBrands() method
5. Ocado - added getKnownBrands() method
6. PetsAtHome - added getKnownBrands() method
7. Sainsburys - added getKnownBrands() method
8. Tesco - added getKnownBrands() method
9. Waitrose - added getKnownBrands() method
10. Zooplus - added getKnownBrands() method

**Key Decisions**:
- Used `config()` helper instead of creating a service class - simpler for read-only data
- Each extractor has a `getKnownBrands()` method that merges core brands with retailer-specific brands
- Kept both single and double-quoted string variants (e.g., `"Lily's Kitchen"` and `'Lily\'s Kitchen'`) to match existing behavior
- Retailer identifiers match existing slug patterns (e.g., 'amazon', 'asda', 'morrisons')

**Pattern to Follow**:
```php
private function getKnownBrands(): array
{
    return array_merge(
        config('brands.known_brands', []),
        config('brands.retailer_specific.{retailer}', [])
    );
}
```

**Results**:
- Removed ~400+ duplicate brand entries across extractors
- All 381 ProductDetailsExtractor tests passing
- Brand extraction still works correctly from titles
- Easy to add new brands in one central location

**Note**: BMProductDetailsExtractor doesn't have a KNOWN_BRANDS constant (only uses 10/11 extractors).

### ✅ Extractor Test Helper Traits (Task f-9e2bb8)
**Completed**: Created reusable test helper traits to consolidate duplicated test setup and assertion patterns across 30+ extractor tests.

**Files Created**:
- `tests/Traits/ExtractorTestHelpers.php` - Main helper trait with:
  - `loadFixture($filename)` - Load HTML fixtures from tests/Fixtures/
  - `extractToArray($generator)` - Convert extractor generator results to array
  - `filterProductListingUrls($results)` - Filter to only ProductListingUrl DTOs
  - `filterProductDetails($results)` - Filter to only ProductDetails DTOs
  - `filterProductReviews($results)` - Filter to only ProductReview DTOs
  - `filterPaginatedUrls($results)` - Filter to only PaginatedUrl DTOs
  - `assertProductListingUrlDto($dto, $retailer, $urlPattern)` - Assert DTO structure
  - `assertProductDetailsDto($dto, $retailer)` - Assert ProductDetails structure
  - `assertProductReviewDto($dto, $source)` - Assert ProductReview structure
  - `assertCanHandleUrl($extractor, $url)` - Assert extractor handles URL
  - `assertCannotHandleUrl($extractor, $url)` - Assert extractor doesn't handle URL
  - `minimalHtml($body)`, `emptyHtml()`, `htmlWithLink()`, `htmlWithLinks()` - HTML generators
  - `htmlWithJsonLd($data)` - Generate HTML with JSON-LD structured data
  - `productJsonLd()`, `reviewJsonLd()` - JSON-LD structure helpers

- `tests/Traits/CanHandleTestCases.php` - canHandle test utilities:
  - `testCanHandleUrls($validUrls, $invalidUrls)` - Test multiple URLs at once
  - `assertHandlesUrls($validUrls, $invalidUrls)` - Alias for testCanHandleUrls
  - `commonInvalidUrls()` - Get common invalid URLs (empty, about, contact)
  - `competitorDomainUrls($exclude)` - Get competitor domain URLs for UK retailers
  - `buildCanHandleDataset($urls, $expected)` - Build dataset for Pest ->with()

- `tests/Unit/Traits/ExtractorTestHelpersTest.php` - Tests for ExtractorTestHelpers trait
- `tests/Unit/Traits/CanHandleTestCasesTest.php` - Tests for CanHandleTestCases trait

**Configuration**:
Updated `tests/Pest.php` to include both traits for all Unit tests:
```php
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->use(Tests\Traits\ExtractorTestHelpers::class)
    ->use(Tests\Traits\CanHandleTestCases::class)
    ->in('Unit');
```

**Key Decisions**:
- Traits are available globally to all Unit tests (not just Extractor tests) for flexibility
- Methods are `protected` to work with Pest's test case binding
- HTML generators help reduce repetitive inline HTML in tests
- JSON-LD helpers support overrides for flexibility in testing
- DTO assertion methods check for all expected keys/types

**Patterns Established**:
```php
// Using fixture loader
$html = $this->loadFixture('tesco-product-page.html');

// Using HTML generators
$html = $this->htmlWithLink('/product/123', 'Product Name');
$html = $this->htmlWithJsonLd($this->productJsonLd(['name' => 'Custom']));

// Filtering results
$results = iterator_to_array($this->extractor->extract($html, $url));
$productUrls = $this->filterProductListingUrls($results);

// Testing canHandle
$this->testCanHandleUrls(
    validUrls: ['https://tesco.com/products/123'],
    invalidUrls: array_values($this->competitorDomainUrls(['tesco']))
);
```

**Results**:
- 28 new trait tests passing
- 317+ existing extractor tests still passing
- ~70% of duplicated test structure can now use shared helpers
- Easy to extend with additional helpers as needed

### Traits
- **`app/Crawler/Extractors/Concerns/NormalizesUrls.php`**: Provides URL normalization functionality for all ProductListingUrlExtractor classes
- **`app/Crawler/Extractors/Concerns/ExtractsJsonLd.php`**: Provides JSON-LD structured data extraction functionality for all ProductDetailsExtractor classes
- **`tests/Traits/ExtractorTestHelpers.php`**: Provides fixture loading, HTML generation, DTO filtering, and assertion helpers for extractor tests
- **`tests/Traits/CanHandleTestCases.php`**: Provides canHandle testing utilities including competitor domain URLs and dataset builders

### ✅ ExtractsJsonLd Trait (Task f-b2bf61)
**Completed**: Created `app/Crawler/Extractors/Concerns/ExtractsJsonLd.php` trait to consolidate duplicate JSON-LD extraction logic.

**Key Decisions**:
- Used the standardized extractJsonLd() method pattern found across all 10 ProductDetailsExtractor classes
- Handles both @graph format (where Product schema is nested in a graph array) and direct Product type format
- Made the method `protected` so it can be used by extractors and overridden if needed
- Included a `logJsonLdError()` method that can be overridden for custom logging with extractor-specific prefixes
- All 10 ProductDetailsExtractor classes now use this trait (BM doesn't use JSON-LD)

**Extractors Updated** (10 total):
1. Amazon - uses trait, removed 37-line duplicate method
2. Asda - uses trait, removed 37-line duplicate method
3. JustForPets - uses trait, removed 37-line duplicate method
4. Morrisons - uses trait, removed 37-line duplicate method
5. Ocado - uses trait, removed 37-line duplicate method
6. PetsAtHome - uses trait, removed 37-line duplicate method
7. Sainsburys - uses trait, removed 37-line duplicate method
8. Tesco - uses trait, removed 37-line duplicate method
9. Waitrose - uses trait, removed 37-line duplicate method
10. Zooplus - uses trait, removed 37-line duplicate method

**Pattern to Follow**:
```php
use App\Crawler\Extractors\Concerns\ExtractsJsonLd;

class MyProductDetailsExtractor implements ExtractorInterface
{
    use ExtractsJsonLd;

    public function extract(string $html, string $url): Generator
    {
        $crawler = new Crawler($html);
        $jsonLdData = $this->extractJsonLd($crawler);
        // Use $jsonLdData in extraction methods...
    }
}
```

**Results**:
- Removed ~370 duplicate lines of code (37 lines × 10 extractors)
- Centralized JSON-LD extraction logic in one maintainable location
- All extractors continue to work correctly with the trait
- Easy to update JSON-LD extraction logic across all extractors by modifying the trait
- Consistent error logging via logJsonLdError() method

**Gotchas**:
- The trait is in `App\Crawler\Extractors\Concerns` namespace, not `App\Crawler\Concerns`
- Make sure to import the trait with `use App\Crawler\Extractors\Concerns\ExtractsJsonLd;`
- The extractJsonLd() method returns an empty array if no Product schema is found, allowing graceful degradation