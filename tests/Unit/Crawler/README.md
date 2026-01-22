# Crawler Tests

Comprehensive test suite for the web scraper system.

## Test Coverage

### Unit Tests (74 tests, 379 assertions)

#### GuzzleHttpAdapter (11 tests)
- ✅ Interface implementation
- ✅ HTML content fetching
- ✅ Exception handling
- ✅ User agent rotation (enabled/disabled)
- ✅ Anti-bot headers (Sec-Fetch-*)
- ✅ Proxy integration via `withProxy()`
- ✅ Status code and headers tracking
- ✅ Custom header merging
- ✅ Redirect handling

#### BrightDataProxyAdapter (12 tests)
- ✅ Interface implementation
- ✅ Availability with/without credentials
- ✅ Proxy URL format generation
- ✅ Session ID rotation
- ✅ Guzzle-compatible config
- ✅ Country configuration
- ✅ Sticky sessions
- ✅ Method chaining (fluent interface)

#### ProxyManager (15 tests)
- ✅ Interface implementation
- ✅ Availability checks
- ✅ Provider management (add/get)
- ✅ Round-robin rotation
- ✅ Failover to available providers
- ✅ Proxy URL and config delegation
- ✅ Empty provider handling
- ✅ Multiple provider rotation

#### NullProxyAdapter (6 tests)
- ✅ Interface implementation
- ✅ Always unavailable
- ✅ Returns null/empty values
- ✅ No-op rotation
- ✅ Explicit "no proxy" option

#### UserAgentRotator (19 tests)
- ✅ User agent loading (32 agents)
- ✅ Random selection
- ✅ Sequential rotation
- ✅ Cycling through all agents
- ✅ Platform filtering (Windows, macOS, Linux, Android, iOS, mobile)
- ✅ Browser filtering (Chrome, Firefox, Safari, Edge, Opera)
- ✅ User agent validity
- ✅ Current browser versions (130+, 17+)

#### Integration Tests (11 tests)
- ✅ Adapter without proxy
- ✅ Adapter with NullProxyAdapter
- ✅ Adapter with BrightDataProxyAdapter
- ✅ Adapter with ProxyManager
- ✅ Proxy rotation when enabled/disabled
- ✅ Fluent interface chaining
- ✅ Shared proxy providers
- ✅ All features enabled together
- ✅ ProxyManager failover
- ✅ Dynamic proxy switching

### Feature Tests (6 tests, 16 assertions)

#### ScraperTester Controller
- ✅ Authentication requirements
- ✅ URL validation (required, format)
- ✅ Valid URL handling
- ✅ Error handling

## Running Tests

### All crawler tests
```bash
php artisan test --testsuite=Unit --filter=Crawler
```

### Specific test file
```bash
php artisan test tests/Unit/Crawler/GuzzleHttpAdapterTest.php
```

### Feature tests
```bash
php artisan test tests/Feature/ScraperTesterTest.php
```

### All tests
```bash
php artisan test
```

## Test Structure

Tests use **Pest PHP** with expect() syntax:

```php
test('adapter implements correct interfaces', function () {
    $adapter = new GuzzleHttpAdapter();

    expect($adapter)
        ->toBeInstanceOf(HttpAdapterInterface::class)
        ->toBeInstanceOf(SupportsProxyInterface::class);
});
```

## Mocking

HTTP requests are mocked using Guzzle's `MockHandler`:

```php
beforeEach(function () {
    $this->mockHandler = new MockHandler();
    $handlerStack = HandlerStack::create($this->mockHandler);
    $this->mockClient = new Client(['handler' => $handlerStack]);
});

test('fetchHtml returns HTML content', function () {
    $this->mockHandler->append(
        new Response(200, ['Content-Type' => 'text/html'], '<html>Test</html>')
    );

    $adapter = new GuzzleHttpAdapter($this->mockClient);
    $html = $adapter->fetchHtml('https://example.com');

    expect($html)->toBe('<html>Test</html>');
});
```

## Coverage Areas

✅ **Happy paths** - Normal operations work correctly
✅ **Error handling** - Failures are handled gracefully
✅ **Edge cases** - Empty inputs, null values, invalid data
✅ **Integration** - Components work together correctly
✅ **Configuration** - All options work as expected
✅ **API contracts** - Interfaces are properly implemented

## What's Not Tested

- Real HTTP requests (all mocked)
- Actual proxy services (credentials mocked)
- UI rendering (Vite assets not built in tests)
- Performance/load testing

## Adding New Tests

1. Create test file in `tests/Unit/Crawler/`
2. Use Pest syntax with `test()` or `it()`
3. Use `expect()` for assertions
4. Mock external dependencies (HTTP, config, etc.)
5. Follow existing patterns

Example:

```php
<?php

use App\Crawler\NewComponent;

test('new component works correctly', function () {
    $component = new NewComponent();

    expect($component->doSomething())->toBeTrue();
});
```

## Test Quality

- **Fast**: < 1 second for full suite
- **Isolated**: No database, no network calls
- **Deterministic**: Always produce same results
- **Clear**: Easy to understand what's being tested
- **Maintainable**: Easy to update when code changes
