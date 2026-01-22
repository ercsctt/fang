<?php

use App\Crawler\Contracts\ProxyAdapterInterface;
use App\Crawler\Proxies\BrightDataProxyAdapter;

test('adapter implements ProxyAdapterInterface', function () {
    $adapter = new BrightDataProxyAdapter();

    expect($adapter)->toBeInstanceOf(ProxyAdapterInterface::class);
});

test('adapter is not available without credentials', function () {
    // Mock config to return empty string values
    config(['services.brightdata.username' => '']);
    config(['services.brightdata.password' => '']);

    $adapter = new BrightDataProxyAdapter(username: '', password: '');

    expect($adapter->isAvailable())->toBeFalse()
        ->and($adapter->getProxyUrl())->toBeNull()
        ->and($adapter->getProxyConfig())->toBe([]);
});

test('adapter is available with credentials', function () {
    $adapter = new BrightDataProxyAdapter(
        username: 'test-user',
        password: 'test-pass',
        host: 'brd.superproxy.io',
        port: 22225
    );

    expect($adapter->isAvailable())->toBeTrue()
        ->and($adapter->getProxyUrl())->toBeString();
});

test('adapter generates correct proxy URL format', function () {
    $adapter = new BrightDataProxyAdapter(
        username: 'test-user',
        password: 'test-pass',
        host: 'brd.superproxy.io',
        port: 22225,
        country: 'gb'
    );

    $proxyUrl = $adapter->getProxyUrl();

    expect($proxyUrl)
        ->toContain('brd.superproxy.io:22225')
        ->toContain('test-pass')
        ->toContain('session-')
        ->toContain('country-gb');
});

test('adapter includes session ID in username', function () {
    $adapter = new BrightDataProxyAdapter(
        username: 'test-user',
        password: 'test-pass'
    );

    $proxyUrl = $adapter->getProxyUrl();

    expect($proxyUrl)->toMatch('/session-\d+/');
});

test('rotate changes session ID', function () {
    $adapter = new BrightDataProxyAdapter(
        username: 'test-user',
        password: 'test-pass'
    );

    $firstUrl = $adapter->getProxyUrl();
    $adapter->rotate();
    $secondUrl = $adapter->getProxyUrl();

    // URLs should be different due to different session IDs
    expect($firstUrl)->not->toBe($secondUrl)
        ->and($firstUrl)->toContain('session-')
        ->and($secondUrl)->toContain('session-');
});

test('getProxyConfig returns Guzzle-compatible array', function () {
    $adapter = new BrightDataProxyAdapter(
        username: 'test-user',
        password: 'test-pass'
    );

    $config = $adapter->getProxyConfig();

    expect($config)->toBeArray()
        ->toHaveKey('proxy')
        ->and($config['proxy'])->toHaveKey('http')
        ->and($config['proxy'])->toHaveKey('https')
        ->and($config['proxy']['http'])->toBe($config['proxy']['https']);
});

test('setCountry updates proxy configuration', function () {
    $adapter = new BrightDataProxyAdapter(
        username: 'test-user',
        password: 'test-pass'
    );

    $adapter->setCountry('us');
    $proxyUrl = $adapter->getProxyUrl();

    expect($proxyUrl)->toContain('country-us');

    $adapter->setCountry('fr');
    $proxyUrl = $adapter->getProxyUrl();

    expect($proxyUrl)->toContain('country-fr');
});

test('setCountry returns self for chaining', function () {
    $adapter = new BrightDataProxyAdapter(
        username: 'test-user',
        password: 'test-pass'
    );

    $result = $adapter->setCountry('gb');

    expect($result)->toBe($adapter);
});

test('stickySession keeps same session ID', function () {
    $adapter = new BrightDataProxyAdapter(
        username: 'test-user',
        password: 'test-pass'
    );

    $adapter->stickySession(12345);

    $firstUrl = $adapter->getProxyUrl();
    $secondUrl = $adapter->getProxyUrl();

    expect($firstUrl)->toBe($secondUrl)
        ->and($firstUrl)->toContain('session-12345');
});

test('stickySession returns self for chaining', function () {
    $adapter = new BrightDataProxyAdapter(
        username: 'test-user',
        password: 'test-pass'
    );

    $result = $adapter->stickySession(12345);

    expect($result)->toBe($adapter);
});

test('adapter can be configured via constructor', function () {
    $adapter = new BrightDataProxyAdapter(
        username: 'user123',
        password: 'pass456',
        zone: 'residential',
        host: 'custom.proxy.io',
        port: 9999,
        country: 'de'
    );

    $proxyUrl = $adapter->getProxyUrl();

    expect($proxyUrl)
        ->toContain('custom.proxy.io:9999')
        ->toContain('pass456')
        ->toContain('country-de');
});
