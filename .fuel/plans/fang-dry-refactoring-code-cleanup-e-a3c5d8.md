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

### Traits
- **`app/Crawler/Extractors/Concerns/NormalizesUrls.php`**: Provides URL normalization functionality for all ProductListingUrlExtractor classes