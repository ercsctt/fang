<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'smoke-test@example.com',
        'password' => Hash::make('password'),
    ]);
});

test('all admin pages load without javascript errors', function () {
    // Login first
    $page = visit('/login')
        ->fill('#email', 'smoke-test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertSee('Dashboard');

    // Visit each admin page and verify no JS errors
    $adminPages = [
        ['/admin/retailers', 'Retailers'],
        ['/admin/retailers/create', 'Create Retailer'],
        ['/admin/crawl-monitoring', 'Crawl Monitoring'],
        ['/admin/product-verification', 'Product Verification'],
    ];

    foreach ($adminPages as [$url, $expectedText]) {
        $page->navigate($url)
            ->assertSee($expectedText)
            ->assertNoJavascriptErrors()
            ->assertNoConsoleLogs();
    }
});

test('admin pages smoke test - retailers', function () {
    $page = visit('/login')
        ->fill('#email', 'smoke-test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertSee('Dashboard');

    $page->navigate('/admin/retailers')
        ->assertSee('Retailers')
        ->assertNoJavascriptErrors()
        ->assertNoConsoleLogs();
});

test('admin pages smoke test - retailers create', function () {
    $page = visit('/login')
        ->fill('#email', 'smoke-test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertSee('Dashboard');

    $page->navigate('/admin/retailers/create')
        ->assertSee('Create Retailer')
        ->assertNoJavascriptErrors()
        ->assertNoConsoleLogs();
});

test('admin pages smoke test - crawl monitoring', function () {
    $page = visit('/login')
        ->fill('#email', 'smoke-test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertSee('Dashboard');

    $page->navigate('/admin/crawl-monitoring')
        ->assertSee('Crawl Monitoring')
        ->assertNoJavascriptErrors()
        ->assertNoConsoleLogs();
});

test('admin pages smoke test - product verification', function () {
    $page = visit('/login')
        ->fill('#email', 'smoke-test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->assertSee('Dashboard');

    $page->navigate('/admin/product-verification')
        ->assertSee('Product Verification')
        ->assertNoJavascriptErrors()
        ->assertNoConsoleLogs();
});
