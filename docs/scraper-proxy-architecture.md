# Scraper & Proxy Architecture

This document describes the redesigned scraper adapter system that separates proxy management from HTTP adapters.

## Overview

The system now uses a capability-based approach where:
- **Proxy Providers** manage proxy credentials and configuration
- **HTTP Adapters** handle fetching HTML from URLs
- **SupportsProxyInterface** indicates which adapters can use external proxies

## Core Interfaces

### HttpAdapterInterface

All HTTP adapters implement this interface to provide a consistent way to fetch HTML:

```php
interface HttpAdapterInterface
{
    public function fetchHtml(string $url, array $options = []): string;
    public function getLastStatusCode(): ?int;
    public function getLastHeaders(): array;
}
```

### ProxyAdapterInterface

Proxy providers implement this interface to supply proxy configuration:

```php
interface ProxyAdapterInterface
{
    public function getProxyUrl(): ?string;
    public function getProxyConfig(): array;
    public function isAvailable(): bool;
    public function rotate(): void;
}
```

### SupportsProxyInterface

HTTP adapters that can use external proxies implement this:

```php
interface SupportsProxyInterface
{
    public function withProxy(ProxyAdapterInterface $proxyAdapter): static;
    public function getProxyAdapter(): ?ProxyAdapterInterface;
}
```

## HTTP Adapters

### GuzzleHttpAdapter

Production-ready Guzzle-based adapter with built-in anti-bot protection.

- **Implements**: `HttpAdapterInterface`, `SupportsProxyInterface`
- **Use case**: All HTTP scraping tasks (from simple to advanced)
- **Features**:
  - User-agent rotation (32 realistic user agents)
  - Anti-bot headers (Sec-Fetch-*, realistic Accept headers)
  - Automatic proxy rotation
  - Comprehensive logging
  - Configurable rotation behavior

```php
// Basic usage (no rotation)
$adapter = new GuzzleHttpAdapter(
    rotateUserAgent: false,
    rotateProxy: false,
);
$html = $adapter->fetchHtml('https://example.com');

// Advanced usage (with rotation - recommended for production)
$adapter = new GuzzleHttpAdapter(
    rotateUserAgent: true,  // Sequential user-agent rotation
    rotateProxy: true,      // Automatic proxy rotation per request
);
$adapter->withProxy(new BrightDataProxyAdapter());
$html = $adapter->fetchHtml('https://example.com');
```


## Proxy Providers

### BrightDataProxyAdapter

Residential/datacenter proxies from BrightData (formerly Luminati).

```php
$proxy = new BrightDataProxyAdapter(
    username: 'your-username',
    password: 'your-password',
    country: 'gb',
);

$proxy->rotate(); // Get a new IP
```

### NullProxyAdapter

For cases where no proxy is needed (direct connection).

```php
$adapter = new GuzzleHttpAdapter();
$adapter->withProxy(new NullProxyAdapter());
```

### ProxyManager

Manages multiple proxy providers with rotation and failover.

```php
$manager = new ProxyManager([
    new BrightDataProxyAdapter(...),
    new OxylabsProxyAdapter(...),
    new SmartProxyAdapter(...),
]);

$adapter = new GuzzleHttpAdapter();
$adapter->withProxy($manager);

// ProxyManager will rotate between providers
$html = $adapter->fetchHtml('https://example.com');
```

## Usage Patterns

### Pattern 1: Check Capability Before Configuring

```php
function configureAdapter(HttpAdapterInterface $adapter, ProxyAdapterInterface $proxy)
{
    if ($adapter instanceof SupportsProxyInterface) {
        $adapter->withProxy($proxy);
    }

    return $adapter->fetchHtml('https://example.com');
}
```

### Pattern 2: Factory with Optional Proxy

```php
function createAdapter(string $type, ?ProxyAdapterInterface $proxy = null)
{
    $adapter = match ($type) {
        'guzzle' => new GuzzleHttpAdapter(),
        'advanced' => new AdvancedScraperAdapter(),
        'scraper-api' => new ScraperApiAdapter(),
    };

    if ($adapter instanceof SupportsProxyInterface && $proxy) {
        $adapter->withProxy($proxy);
    }

    return $adapter;
}
```

### Pattern 3: Multiple Proxy Providers

```php
$proxyManager = new ProxyManager();
$proxyManager->addProvider(new BrightDataProxyAdapter());
$proxyManager->addProvider(new OxylabsProxyAdapter());

$adapter = new AdvancedScraperAdapter();
$adapter->withProxy($proxyManager);
```

## Design Benefits

1. **Separation of Concerns**: Proxies are separate from HTTP adapters
2. **Capability-Based**: Only adapters that support proxies implement `SupportsProxyInterface`
3. **Flexible**: Easy to add new proxy providers or HTTP adapters
4. **Type-Safe**: Interface contracts ensure correct usage
5. **Self-Documenting**: Clear which adapters support proxies and which handle them internally

## Configuration Options

`GuzzleHttpAdapter` is highly configurable:

```php
new GuzzleHttpAdapter(
    rotateUserAgent: true,   // true: sequential rotation, false: random selection
    rotateProxy: true,       // true: rotate on each request, false: sticky session
)
```

**Recommended configurations:**

- **Production scraping**: `rotateUserAgent: true, rotateProxy: true`
- **Testing/development**: `rotateUserAgent: false, rotateProxy: false`
- **Sticky sessions**: `rotateUserAgent: true, rotateProxy: false`

## Adding New Components

### Adding a New Proxy Provider

1. Implement `ProxyAdapterInterface`
2. Handle proxy URL formatting and rotation
3. Use with any adapter that implements `SupportsProxyInterface`

```php
class OxylabsProxyAdapter implements ProxyAdapterInterface
{
    public function getProxyUrl(): ?string { /* ... */ }
    public function getProxyConfig(): array { /* ... */ }
    public function isAvailable(): bool { /* ... */ }
    public function rotate(): void { /* ... */ }
}
```

### Adding a New HTTP Adapter

1. Implement `HttpAdapterInterface` (required)
2. Optionally implement `SupportsProxyInterface` if it can use external proxies
3. If the adapter handles proxies internally (like browser APIs), don't implement `SupportsProxyInterface`

```php
class CustomAdapter implements HttpAdapterInterface, SupportsProxyInterface
{
    private ?ProxyAdapterInterface $proxyAdapter = null;

    public function fetchHtml(string $url, array $options = []): string { /* ... */ }
    public function withProxy(ProxyAdapterInterface $proxy): static { /* ... */ }
    public function getProxyAdapter(): ?ProxyAdapterInterface { /* ... */ }
}
```

## Scraper Tester UI

A web-based scraper tester is available at `/scraper-tester` (requires authentication).

**Features:**
- Test any URL with the scraper
- Toggle user-agent rotation
- Toggle proxy usage
- View full HTML response
- View response headers
- See response size and status code
- Copy HTML to clipboard

This is useful for:
- Testing different URLs before scraping
- Debugging scraper issues
- Verifying proxy configuration
- Checking HTML structure
