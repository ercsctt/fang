<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);
});

test('sidebar displays all admin links', function () {
    $page = visit('/login')
        ->fill('#email', 'test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->waitForText('Dashboard');

    // Verify all navigation links are visible in sidebar
    $page->assertSee('Dashboard')
        ->assertSee('Retailers Management')
        ->assertSee('Product Verification')
        ->assertSee('Crawl Monitoring')
        ->assertSee('Scraper Tester')
        ->assertNoJavascriptErrors();
});

test('dashboard link works', function () {
    $page = visit('/login')
        ->fill('#email', 'test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->waitForText('Dashboard');

    // Navigate away first, then back to dashboard
    $page->navigate('/admin/retailers')
        ->waitForText('Retailers');

    // Click the Dashboard link in sidebar
    $page->click('[data-sidebar="menu-button"]:has-text("Dashboard")')
        ->waitForText('Dashboard')
        ->assertUrlIs('/dashboard')
        ->assertNoJavascriptErrors();
});

test('retailers link works', function () {
    $page = visit('/login')
        ->fill('#email', 'test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->waitForText('Dashboard');

    // Click the Retailers Management link in sidebar
    $page->click('[data-sidebar="menu-button"]:has-text("Retailers Management")')
        ->waitForText('Retailers')
        ->assertUrlIs('/admin/retailers')
        ->assertSee('Manage retailer status')
        ->assertNoJavascriptErrors();
});

test('crawl monitoring link works', function () {
    $page = visit('/login')
        ->fill('#email', 'test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->waitForText('Dashboard');

    // Click the Crawl Monitoring link in sidebar
    $page->click('[data-sidebar="menu-button"]:has-text("Crawl Monitoring")')
        ->waitForText('Crawl Monitoring')
        ->assertUrlIs('/admin/crawl-monitoring')
        ->assertNoJavascriptErrors();
});

test('product verification link works', function () {
    $page = visit('/login')
        ->fill('#email', 'test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->waitForText('Dashboard');

    // Click the Product Verification link in sidebar
    $page->click('[data-sidebar="menu-button"]:has-text("Product Verification")')
        ->waitForText('Product Verification')
        ->assertUrlIs('/admin/product-verification')
        ->assertNoJavascriptErrors();
});

test('active link highlighting', function () {
    $page = visit('/login')
        ->fill('#email', 'test@example.com')
        ->fill('#password', 'password')
        ->click('[data-test="login-button"]')
        ->waitForText('Dashboard');

    // Verify Dashboard is highlighted when on dashboard
    $page->assertPresent('[data-sidebar="menu-button"][data-active="true"]:has-text("Dashboard")')
        ->assertNoJavascriptErrors();

    // Navigate to Retailers and verify it becomes highlighted
    $page->click('[data-sidebar="menu-button"]:has-text("Retailers Management")')
        ->waitForText('Retailers')
        ->assertPresent('[data-sidebar="menu-button"][data-active="true"]:has-text("Retailers Management")')
        ->assertNotPresent('[data-sidebar="menu-button"][data-active="true"]:has-text("Dashboard")');

    // Navigate to Crawl Monitoring and verify it becomes highlighted
    $page->click('[data-sidebar="menu-button"]:has-text("Crawl Monitoring")')
        ->waitForText('Crawl Monitoring')
        ->assertPresent('[data-sidebar="menu-button"][data-active="true"]:has-text("Crawl Monitoring")')
        ->assertNotPresent('[data-sidebar="menu-button"][data-active="true"]:has-text("Retailers Management")');

    // Navigate to Product Verification and verify it becomes highlighted
    $page->click('[data-sidebar="menu-button"]:has-text("Product Verification")')
        ->waitForText('Product Verification')
        ->assertPresent('[data-sidebar="menu-button"][data-active="true"]:has-text("Product Verification")')
        ->assertNotPresent('[data-sidebar="menu-button"][data-active="true"]:has-text("Crawl Monitoring")');
});
