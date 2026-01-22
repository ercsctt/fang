<?php

use App\Crawler\Contracts\ProxyAdapterInterface;
use App\Crawler\Proxies\NullProxyAdapter;

test('adapter implements ProxyAdapterInterface', function () {
    $adapter = new NullProxyAdapter();

    expect($adapter)->toBeInstanceOf(ProxyAdapterInterface::class);
});

test('adapter is never available', function () {
    $adapter = new NullProxyAdapter();

    expect($adapter->isAvailable())->toBeFalse();
});

test('getProxyUrl returns null', function () {
    $adapter = new NullProxyAdapter();

    expect($adapter->getProxyUrl())->toBeNull();
});

test('getProxyConfig returns empty array', function () {
    $adapter = new NullProxyAdapter();

    expect($adapter->getProxyConfig())->toBe([]);
});

test('rotate does nothing', function () {
    $adapter = new NullProxyAdapter();

    // Should not throw any errors
    $adapter->rotate();

    expect($adapter->isAvailable())->toBeFalse()
        ->and($adapter->getProxyUrl())->toBeNull();
});

test('adapter can be used as explicit no-proxy option', function () {
    $adapter = new NullProxyAdapter();

    // Verify it's a valid proxy adapter that explicitly does nothing
    expect($adapter)->toBeInstanceOf(ProxyAdapterInterface::class)
        ->and($adapter->isAvailable())->toBeFalse()
        ->and($adapter->getProxyConfig())->toBeArray()->toBeEmpty();
});
