<?php

use App\Crawler\Adapters\GuzzleHttpAdapter;
use App\Crawler\Proxies\BrightDataProxyAdapter;
use App\Crawler\Proxies\NullProxyAdapter;
use App\Crawler\Proxies\ProxyManager;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    $this->mockHandler = new MockHandler();
    $handlerStack = HandlerStack::create($this->mockHandler);
    $this->mockClient = new Client(['handler' => $handlerStack]);
});

test('adapter works without proxy', function () {
    $this->mockHandler->append(new Response(200, [], '<html>No proxy</html>'));

    $adapter = new GuzzleHttpAdapter($this->mockClient);
    $html = $adapter->fetchHtml('https://example.com');

    expect($html)->toBe('<html>No proxy</html>')
        ->and($adapter->getProxyAdapter())->toBeNull();
});

test('adapter works with NullProxyAdapter', function () {
    $this->mockHandler->append(new Response(200, [], '<html>Null proxy</html>'));

    $adapter = new GuzzleHttpAdapter($this->mockClient);
    $adapter->withProxy(new NullProxyAdapter());

    $html = $adapter->fetchHtml('https://example.com');

    expect($html)->toBe('<html>Null proxy</html>')
        ->and($adapter->getProxyAdapter())->toBeInstanceOf(NullProxyAdapter::class);
});

test('adapter integrates with BrightData proxy', function () {
    $this->mockHandler->append(new Response(200, [], '<html>With proxy</html>'));

    $proxy = new BrightDataProxyAdapter(
        username: 'test-user',
        password: 'test-pass'
    );

    $adapter = new GuzzleHttpAdapter($this->mockClient);
    $adapter->withProxy($proxy);

    $html = $adapter->fetchHtml('https://example.com');

    expect($html)->toBe('<html>With proxy</html>')
        ->and($adapter->getProxyAdapter())->toBe($proxy);
});

test('adapter integrates with ProxyManager', function () {
    $this->mockHandler->append(new Response(200, [], '<html>Manager proxy</html>'));

    $proxy1 = new BrightDataProxyAdapter(username: 'user1', password: 'pass1');
    $proxy2 = new BrightDataProxyAdapter(username: 'user2', password: 'pass2');
    $manager = new ProxyManager([$proxy1, $proxy2]);

    $adapter = new GuzzleHttpAdapter($this->mockClient);
    $adapter->withProxy($manager);

    $html = $adapter->fetchHtml('https://example.com');

    expect($html)->toBe('<html>Manager proxy</html>')
        ->and($adapter->getProxyAdapter())->toBe($manager)
        ->and($manager->isAvailable())->toBeTrue();
});

test('adapter rotates proxy when configured', function () {
    $this->mockHandler->append(
        new Response(200, [], 'response1'),
        new Response(200, [], 'response2')
    );

    $proxy = new BrightDataProxyAdapter(username: 'user', password: 'pass');
    $adapter = new GuzzleHttpAdapter($this->mockClient, rotateProxy: true);
    $adapter->withProxy($proxy);

    $firstUrl = $proxy->getProxyUrl();
    $adapter->fetchHtml('https://example.com');
    $secondUrl = $proxy->getProxyUrl();

    // Proxy should have rotated
    expect($firstUrl)->not->toBe($secondUrl);
});

test('adapter does not rotate proxy when disabled', function () {
    $this->mockHandler->append(
        new Response(200, [], 'response1'),
        new Response(200, [], 'response2')
    );

    $proxy = new BrightDataProxyAdapter(username: 'user', password: 'pass');
    $adapter = new GuzzleHttpAdapter($this->mockClient, rotateProxy: false);
    $adapter->withProxy($proxy);

    $firstUrl = $proxy->getProxyUrl();
    $adapter->fetchHtml('https://example.com');
    $secondUrl = $proxy->getProxyUrl();

    // Proxy should be the same (sticky session)
    expect($firstUrl)->toBe($secondUrl);
});

test('fluent interface allows chaining', function () {
    $this->mockHandler->append(new Response(200, [], 'response'));

    $proxy = new BrightDataProxyAdapter(username: 'user', password: 'pass');

    $adapter = (new GuzzleHttpAdapter($this->mockClient))
        ->withProxy($proxy);

    $html = $adapter->fetchHtml('https://example.com');

    expect($html)->toBe('response')
        ->and($adapter->getProxyAdapter())->toBe($proxy);
});

test('multiple adapters can share same proxy provider', function () {
    $this->mockHandler->append(
        new Response(200, [], 'response1'),
        new Response(200, [], 'response2')
    );

    $proxy = new BrightDataProxyAdapter(username: 'user', password: 'pass');

    $adapter1 = new GuzzleHttpAdapter($this->mockClient);
    $adapter1->withProxy($proxy);

    $adapter2 = new GuzzleHttpAdapter($this->mockClient);
    $adapter2->withProxy($proxy);

    $adapter1->fetchHtml('https://example.com');
    $adapter2->fetchHtml('https://example.com');

    expect($adapter1->getProxyAdapter())->toBe($proxy)
        ->and($adapter2->getProxyAdapter())->toBe($proxy);
});

test('adapter configuration works with all features enabled', function () {
    $this->mockHandler->append(new Response(200, [], '<html>Full featured</html>'));

    $proxy = new BrightDataProxyAdapter(username: 'user', password: 'pass');

    $adapter = new GuzzleHttpAdapter(
        client: $this->mockClient,
        rotateUserAgent: true,
        rotateProxy: true
    );
    $adapter->withProxy($proxy);

    $html = $adapter->fetchHtml('https://example.com');

    expect($html)->toBe('<html>Full featured</html>')
        ->and($adapter->getProxyAdapter())->toBe($proxy);

    // Verify request had all the expected headers
    $request = $this->mockHandler->getLastRequest();
    expect($request->hasHeader('User-Agent'))->toBeTrue()
        ->and($request->hasHeader('Sec-Fetch-Dest'))->toBeTrue()
        ->and($request->hasHeader('Sec-Fetch-Mode'))->toBeTrue();
});

test('ProxyManager failover works with adapters', function () {
    $this->mockHandler->append(new Response(200, [], 'response'));

    // One unavailable, one available
    $unavailable = new NullProxyAdapter();
    $available = new BrightDataProxyAdapter(username: 'user', password: 'pass');

    $manager = new ProxyManager([$unavailable, $available]);
    $adapter = new GuzzleHttpAdapter($this->mockClient);
    $adapter->withProxy($manager);

    // Should use the available proxy
    expect($manager->isAvailable())->toBeTrue()
        ->and($manager->getCurrentProvider())->toBe($available);

    $html = $adapter->fetchHtml('https://example.com');
    expect($html)->toBe('response');
});

test('can switch proxy providers dynamically', function () {
    $this->mockHandler->append(
        new Response(200, [], 'response1'),
        new Response(200, [], 'response2')
    );

    $proxy1 = new BrightDataProxyAdapter(username: 'user1', password: 'pass1');
    $proxy2 = new BrightDataProxyAdapter(username: 'user2', password: 'pass2');

    $adapter = new GuzzleHttpAdapter($this->mockClient);

    // Use first proxy
    $adapter->withProxy($proxy1);
    $adapter->fetchHtml('https://example.com');
    expect($adapter->getProxyAdapter())->toBe($proxy1);

    // Switch to second proxy
    $adapter->withProxy($proxy2);
    $adapter->fetchHtml('https://example.com');
    expect($adapter->getProxyAdapter())->toBe($proxy2);
});
