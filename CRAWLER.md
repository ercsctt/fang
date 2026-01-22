# Web Crawler System

A job-based, event-sourced web crawler system for extracting product listings from UK pet retailers.

## Architecture

### Core Components

1. **HTTP Adapters** (app/Crawler/Adapters)
   - `HttpAdapterInterface`: Contract for fetching HTML content
   - `GuzzleHttpAdapter`: Production-ready adapter with user-agent rotation, anti-bot headers, proxy support, and logging

2. **Proxy Adapters** (app/Crawler/Proxies)
   - `ProxyAdapterInterface`: Contract for proxy providers
   - `BrightDataProxyAdapter`: BrightData residential/datacenter proxies with automatic rotation

3. **Services** (app/Crawler/Services)
   - `UserAgentRotator`: Maintains pool of 35+ realistic browser user agents with filtering

4. **Crawlers** (app/Crawler/Scrapers)
   - `BaseCrawler`: Abstract base class for all crawlers
   - `BMCrawler`: B&M specific implementation

5. **Extractors** (app/Crawler/Extractors)
   - `ExtractorInterface`: Contract for extracting data from HTML
   - `BMProductListingUrlExtractor`: Extracts product URLs from B&M pages

6. **DTOs** (app/Crawler/DTOs)
   - `ProductListingUrl`: Data transfer object for discovered product URLs

7. **Jobs** (app/Jobs/Crawler)
   - `CrawlProductListingsJob`: Queued job for crawling URLs

8. **Event Sourcing** (app/Domain/Crawler)
   - Events: `CrawlStarted`, `ProductListingDiscovered`, `CrawlCompleted`, `CrawlFailed`
   - Aggregate: `CrawlAggregate` - manages crawl session state

## Getting Started

### Setup

1. Install dependencies (already done):
```bash
composer require spatie/laravel-event-sourcing guzzlehttp/guzzle symfony/dom-crawler
```

2. Publish event sourcing migrations (already done):
```bash
php artisan vendor:publish --tag=event-sourcing-migrations
php artisan vendor:publish --tag=event-sourcing-config
```

3. Run migrations:
```bash
php artisan migrate
```

4. Configure your database, queue, and BrightData proxy in `.env`:
```env
DB_CONNECTION=pgsql
QUEUE_CONNECTION=redis

# Optional: BrightData Proxy for bypassing anti-bot protection
BRIGHTDATA_USERNAME=your-username
BRIGHTDATA_PASSWORD=your-password
BRIGHTDATA_ZONE=residential
BRIGHTDATA_COUNTRY=gb
```

### Running the B&M Crawler

#### Option 1: Via Artisan Command (Recommended)

Queue jobs for all B&M starting URLs:
```bash
php artisan crawler:bm
```

Run synchronously (useful for testing):
```bash
php artisan crawler:bm --sync
```

Specify a queue:
```bash
php artisan crawler:bm --queue=crawler
```

#### Option 2: Manually Dispatch Jobs

```php
use App\Crawler\Scrapers\BMCrawler;
use App\Jobs\Crawler\CrawlProductListingsJob;

dispatch(new CrawlProductListingsJob(
    crawlerClass: BMCrawler::class,
    url: 'https://www.bmstores.co.uk/pets/dog-food',
));
```

### Monitoring

Monitor logs in real-time:
```bash
php artisan pail
```

Run the queue worker:
```bash
php artisan queue:work
```

## Adding New Retailers

To add a new retailer crawler:

1. Create an extractor in `app/Crawler/Extractors`:
```php
class TescoPetProductExtractor implements ExtractorInterface
{
    public function extract(string $html, string $url): Generator
    {
        // Extract product URLs from HTML
        yield new ProductListingUrl(...);
    }

    public function canHandle(string $url): bool
    {
        return str_contains($url, 'tesco.com');
    }
}
```

2. Create a crawler in `app/Crawler/Scrapers`:
```php
class TescoCrawler extends BaseCrawler
{
    public function __construct(HttpAdapterInterface $httpAdapter)
    {
        parent::__construct($httpAdapter);
        $this->addExtractor(new TescoPetProductExtractor());
    }

    public function getRetailerName(): string
    {
        return 'Tesco';
    }

    public function getStartingUrls(): array
    {
        return ['https://www.tesco.com/groceries/en-GB/shop/pets'];
    }
}
```

3. Create a command to run it:
```bash
php artisan make:command CrawlTescoProductListings
```

## Configuration

### Rate Limiting

Each crawler can specify its own request delay:

```php
public function getRequestDelay(): int
{
    return 2000; // 2 seconds between requests
}
```

### HTTP Adapter Selection

The crawler uses `GuzzleHttpAdapter` which includes:
- **Rotating user agents**: 32 realistic browser user agents
- **Proxy support**: Automatic BrightData proxy integration if configured
- **Realistic headers**: Browser-like HTTP headers with anti-bot protection (Sec-Fetch-*)
- **Auto-rotation**: Proxies and user agents rotate on each request (when enabled)
- **Comprehensive logging**: Debug logs for all requests

```php
// Production mode with all features (recommended)
$adapter = new GuzzleHttpAdapter(
    rotateUserAgent: true,
    rotateProxy: true,
);
$adapter->withProxy(new BrightDataProxyAdapter());

// Basic mode without rotation
$adapter = new GuzzleHttpAdapter(
    rotateUserAgent: false,
    rotateProxy: false,
);
```

### Proxy Configuration

**BrightData** (Residential Proxies)
1. Sign up at https://brightdata.com/
2. Create a proxy zone (residential recommended)
3. Add credentials to `.env`:
```env
BRIGHTDATA_USERNAME=brd-customer-hl_xxxxx-zone-residential
BRIGHTDATA_PASSWORD=your_password
BRIGHTDATA_ZONE=residential
BRIGHTDATA_COUNTRY=gb  # UK proxies
```

The system will automatically:
- Detect proxy configuration
- Rotate IPs on each request
- Use session persistence when needed
- Log proxy usage (credentials masked)

**User Agent Rotation**

The `UserAgentRotator` service maintains 35+ realistic user agents:
```php
$rotator = new UserAgentRotator();

// Get random user agent
$ua = $rotator->random();

// Get next in rotation
$ua = $rotator->next();

// Filter by platform
$windowsAgents = $rotator->byPlatform('windows');
$mobileAgents = $rotator->byPlatform('mobile');

// Filter by browser
$chromeAgents = $rotator->byBrowser('chrome');
$safariAgents = $rotator->byBrowser('safari');
```

### Custom Request Options

Override in your crawler:

```php
protected function getRequestOptions(): array
{
    return [
        'headers' => [
            'Accept-Language' => 'en-GB,en;q=0.9',
        ],
        'timeout' => 60,
    ];
}
```

## Event Sourcing

All crawler activities are tracked via events:

```php
// Query events
$events = StoredEvent::query()
    ->whereEventClass(ProductListingDiscovered::class)
    ->get();

// Replay events
$aggregate = CrawlAggregate::retrieve($crawlId);
```

## Next Steps

1. Create models for storing discovered product listings
2. Add projectors to update read models from events
3. Implement product detail scraping (extracting price, description, etc.)
4. Add support for pagination in extractors
5. Create a dashboard to monitor crawler progress
6. Implement deduplication logic for discovered URLs
7. Add retry logic for failed crawls
8. Set up scheduled crawling (daily updates)

## Testing

Test a crawler manually:

```php
use App\Crawler\Adapters\GuzzleHttpAdapter;
use App\Crawler\Scrapers\BMCrawler;

$crawler = new BMCrawler(new GuzzleHttpAdapter());

foreach ($crawler->crawl('https://www.bmstores.co.uk/pets/dog-food') as $listing) {
    dump($listing->url);
}
```
