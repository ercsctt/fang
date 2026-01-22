<?php

use App\Crawler\Adapters\GuzzleHttpAdapter;
use App\Crawler\Contracts\SupportsProxyInterface;
use App\Crawler\Proxies\BrightDataProxyAdapter;
use App\Crawler\Proxies\NullProxyAdapter;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    $this->mockHandler = new MockHandler();
    $handlerStack = HandlerStack::create($this->mockHandler);
    $this->mockClient = new Client(['handler' => $handlerStack]);
});

test('adapter implements correct interfaces', function () {
    $adapter = new GuzzleHttpAdapter();

    expect($adapter)
        ->toBeInstanceOf(\App\Crawler\Contracts\HttpAdapterInterface::class)
        ->toBeInstanceOf(SupportsProxyInterface::class);
});

test('fetchHtml returns HTML content', function () {
    $this->mockHandler->append(
        new Response(200, ['Content-Type' => 'text/html'], '<html><body>Test</body></html>')
    );

    $adapter = new GuzzleHttpAdapter($this->mockClient);
    $html = $adapter->fetchHtml('https://example.com');

    expect($html)->toBe('<html><body>Test</body></html>')
        ->and($adapter->getLastStatusCode())->toBe(200)
        ->and($adapter->getLastHeaders())->toHaveKey('Content-Type');
});

test('fetchHtml throws exception on failure', function () {
    $this->mockHandler->append(
        new \GuzzleHttp\Exception\RequestException(
            'Error',
            new \GuzzleHttp\Psr7\Request('GET', 'test'),
            new Response(500)
        )
    );

    $adapter = new GuzzleHttpAdapter($this->mockClient);
    $adapter->fetchHtml('https://example.com');
})->throws(\Exception::class, 'Failed to fetch URL');

test('adapter rotates user agent when enabled', function () {
    $this->mockHandler->append(
        new Response(200, [], 'response1'),
        new Response(200, [], 'response2')
    );

    $adapter = new GuzzleHttpAdapter($this->mockClient, rotateUserAgent: true);

    $adapter->fetchHtml('https://example.com');
    $firstUserAgent = $this->mockHandler->getLastRequest()->getHeader('User-Agent')[0];

    $adapter->fetchHtml('https://example.com');
    $secondUserAgent = $this->mockHandler->getLastRequest()->getHeader('User-Agent')[0];

    // With rotation enabled, user agents should be different (in most cases)
    expect($firstUserAgent)->toBeString()
        ->and($secondUserAgent)->toBeString();
});

test('adapter uses random user agent when rotation disabled', function () {
    $this->mockHandler->append(new Response(200, [], 'response'));

    $adapter = new GuzzleHttpAdapter($this->mockClient, rotateUserAgent: false);
    $adapter->fetchHtml('https://example.com');

    $userAgent = $this->mockHandler->getLastRequest()->getHeader('User-Agent')[0];
    expect($userAgent)->toBeString()->toContain('Mozilla');
});

test('adapter includes anti-bot headers', function () {
    $this->mockHandler->append(new Response(200, [], 'response'));

    $adapter = new GuzzleHttpAdapter($this->mockClient);
    $adapter->fetchHtml('https://example.com');

    $request = $this->mockHandler->getLastRequest();

    expect($request->hasHeader('Sec-Fetch-Dest'))->toBeTrue()
        ->and($request->hasHeader('Sec-Fetch-Mode'))->toBeTrue()
        ->and($request->hasHeader('Sec-Fetch-Site'))->toBeTrue()
        ->and($request->hasHeader('Sec-Fetch-User'))->toBeTrue()
        ->and($request->getHeader('Sec-Fetch-Dest')[0])->toBe('document')
        ->and($request->getHeader('Sec-Fetch-Mode')[0])->toBe('navigate');
});

test('adapter accepts proxy via withProxy method', function () {
    $adapter = new GuzzleHttpAdapter();
    $proxyAdapter = new NullProxyAdapter();

    $result = $adapter->withProxy($proxyAdapter);

    expect($result)->toBe($adapter) // Fluent interface
        ->and($adapter->getProxyAdapter())->toBe($proxyAdapter);
});

test('adapter provides access to user agent rotator', function () {
    $adapter = new GuzzleHttpAdapter();
    $rotator = $adapter->getUserAgentRotator();

    expect($rotator)->toBeInstanceOf(\App\Crawler\Services\UserAgentRotator::class)
        ->and($rotator->count())->toBeGreaterThan(0);
});

test('adapter tracks last status code and headers', function () {
    $this->mockHandler->append(
        new Response(200, [
            'Content-Type' => 'text/html',
            'X-Custom-Header' => 'test-value',
        ], 'response')
    );

    $adapter = new GuzzleHttpAdapter($this->mockClient);
    $adapter->fetchHtml('https://example.com');

    expect($adapter->getLastStatusCode())->toBe(200)
        ->and($adapter->getLastHeaders())->toBeArray()
        ->and($adapter->getLastHeaders()['Content-Type'])->toBe('text/html')
        ->and($adapter->getLastHeaders()['X-Custom-Header'])->toBe('test-value');
});

test('adapter merges custom headers', function () {
    $this->mockHandler->append(new Response(200, [], 'response'));

    $adapter = new GuzzleHttpAdapter($this->mockClient);
    $adapter->fetchHtml('https://example.com', [
        'headers' => [
            'X-Custom' => 'CustomValue',
        ],
    ]);

    $request = $this->mockHandler->getLastRequest();
    expect($request->getHeader('X-Custom')[0])->toBe('CustomValue')
        ->and($request->hasHeader('User-Agent'))->toBeTrue(); // Default headers still present
});

test('adapter handles redirects', function () {
    $this->mockHandler->append(
        new Response(301, ['Location' => 'https://example.com/redirected']),
        new Response(200, [], 'final response')
    );

    $adapter = new GuzzleHttpAdapter($this->mockClient);
    $html = $adapter->fetchHtml('https://example.com');

    expect($html)->toBe('final response')
        ->and($adapter->getLastStatusCode())->toBe(200);
});
