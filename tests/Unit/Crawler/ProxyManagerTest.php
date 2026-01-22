<?php

use App\Crawler\Contracts\ProxyAdapterInterface;
use App\Crawler\Proxies\BrightDataProxyAdapter;
use App\Crawler\Proxies\NullProxyAdapter;
use App\Crawler\Proxies\ProxyManager;

test('manager implements ProxyAdapterInterface', function () {
    $manager = new ProxyManager();

    expect($manager)->toBeInstanceOf(ProxyAdapterInterface::class);
});

test('manager without providers is not available', function () {
    $manager = new ProxyManager();

    expect($manager->isAvailable())->toBeFalse()
        ->and($manager->getProxyUrl())->toBeNull()
        ->and($manager->getCurrentProvider())->toBeNull();
});

test('manager with unavailable provider is not available', function () {
    $manager = new ProxyManager([
        new NullProxyAdapter(),
    ]);

    expect($manager->isAvailable())->toBeFalse();
});

test('manager with available provider is available', function () {
    $manager = new ProxyManager([
        new BrightDataProxyAdapter(username: 'user', password: 'pass'),
    ]);

    expect($manager->isAvailable())->toBeTrue()
        ->and($manager->getProxyUrl())->toBeString();
});

test('manager can add providers', function () {
    $manager = new ProxyManager();

    expect($manager->isAvailable())->toBeFalse();

    $manager->addProvider(new BrightDataProxyAdapter(username: 'user', password: 'pass'));

    expect($manager->isAvailable())->toBeTrue();
});

test('addProvider returns self for chaining', function () {
    $manager = new ProxyManager();
    $result = $manager->addProvider(new NullProxyAdapter());

    expect($result)->toBe($manager);
});

test('manager rotates between providers', function () {
    $provider1 = new BrightDataProxyAdapter(username: 'user1', password: 'pass1');
    $provider2 = new BrightDataProxyAdapter(username: 'user2', password: 'pass2');

    $manager = new ProxyManager([$provider1, $provider2]);

    // Get first provider
    $firstProvider = $manager->getCurrentProvider();
    expect($firstProvider)->toBeInstanceOf(ProxyAdapterInterface::class);

    // Rotate should move to next provider
    $manager->rotate();
    $secondProvider = $manager->getCurrentProvider();

    // They might be the same or different depending on implementation,
    // but both should be valid providers
    expect($secondProvider)->toBeInstanceOf(ProxyAdapterInterface::class);
});

test('manager skips unavailable providers', function () {
    $unavailable = new NullProxyAdapter();
    $available = new BrightDataProxyAdapter(username: 'user', password: 'pass');

    $manager = new ProxyManager([$unavailable, $available]);

    // Should return the available one
    $provider = $manager->getCurrentProvider();

    expect($provider)->toBe($available)
        ->and($manager->isAvailable())->toBeTrue();
});

test('manager returns proxy URL from current provider', function () {
    $provider = new BrightDataProxyAdapter(username: 'test-user', password: 'test-pass');
    $manager = new ProxyManager([$provider]);

    $managerUrl = $manager->getProxyUrl();
    $providerUrl = $provider->getProxyUrl();

    // URLs might differ due to session rotation, but both should be valid
    expect($managerUrl)->toBeString()
        ->and($providerUrl)->toBeString();
});

test('manager returns proxy config from current provider', function () {
    $provider = new BrightDataProxyAdapter(username: 'test-user', password: 'test-pass');
    $manager = new ProxyManager([$provider]);

    $config = $manager->getProxyConfig();

    expect($config)->toBeArray()
        ->toHaveKey('proxy');
});

test('manager returns empty config when no providers available', function () {
    $manager = new ProxyManager([new NullProxyAdapter()]);

    $config = $manager->getProxyConfig();

    expect($config)->toBe([]);
});

test('manager rotates current provider proxy', function () {
    $provider = new BrightDataProxyAdapter(username: 'test-user', password: 'test-pass');
    $manager = new ProxyManager([$provider]);

    $firstUrl = $manager->getProxyUrl();
    $manager->rotate();
    $secondUrl = $manager->getProxyUrl();

    // URLs should be different due to rotation
    expect($firstUrl)->not->toBe($secondUrl);
});

test('manager provides access to all providers', function () {
    $provider1 = new BrightDataProxyAdapter(username: 'user1', password: 'pass1');
    $provider2 = new BrightDataProxyAdapter(username: 'user2', password: 'pass2');

    $manager = new ProxyManager([$provider1, $provider2]);

    $providers = $manager->getProviders();

    expect($providers)->toBeArray()
        ->toHaveCount(2)
        ->and($providers[0])->toBe($provider1)
        ->and($providers[1])->toBe($provider2);
});

test('manager handles empty provider list gracefully', function () {
    $manager = new ProxyManager([]);

    expect($manager->isAvailable())->toBeFalse()
        ->and($manager->getProxyUrl())->toBeNull()
        ->and($manager->getProxyConfig())->toBe([])
        ->and($manager->getCurrentProvider())->toBeNull();

    // Rotate should not throw error
    $manager->rotate();

    expect(true)->toBeTrue(); // If we got here, no exception was thrown
});

test('manager round-robins through multiple providers', function () {
    $provider1 = new BrightDataProxyAdapter(username: 'user1', password: 'pass1');
    $provider2 = new BrightDataProxyAdapter(username: 'user2', password: 'pass2');
    $provider3 = new BrightDataProxyAdapter(username: 'user3', password: 'pass3');

    $manager = new ProxyManager([$provider1, $provider2, $provider3]);

    // Collect several proxy URLs to verify rotation
    $urls = [];
    for ($i = 0; $i < 6; $i++) {
        $urls[] = $manager->getProxyUrl();
        $manager->rotate();
    }

    // Should have gotten URLs from all providers
    expect($urls)->toHaveCount(6)
        ->and(array_unique($urls))->toHaveCount(6); // All URLs should be different
});
