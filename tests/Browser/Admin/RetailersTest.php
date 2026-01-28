<?php

declare(strict_types=1);

use App\Enums\RetailerStatus;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]);
});

/**
 * Helper function to login and visit a page
 */
function loginAndVisit(User $user, string $url): mixed
{
    return visit('/login')
        ->type('input[name="email"]', $user->email)
        ->type('input[name="password"]', 'password')
        ->click('button[type="submit"]')
        ->waitForNavigation()
        ->visit($url);
}

test('retailers index page loads', function () {
    Retailer::factory()->create(['name' => 'Test Retailer A']);
    Retailer::factory()->create(['name' => 'Test Retailer B']);

    $page = loginAndVisit($this->user, '/admin/retailers');

    $page->assertSee('Retailers')
        ->assertSee('Manage retailer status and monitor crawl health')
        ->assertSee('Test Retailer A')
        ->assertSee('Test Retailer B')
        ->assertNoJavascriptErrors();
});

test('retailers can be filtered by status', function () {
    Retailer::factory()->create([
        'name' => 'Active Store',
        'status' => RetailerStatus::Active,
    ]);
    Retailer::factory()->paused()->create([
        'name' => 'Paused Store',
    ]);
    Retailer::factory()->disabled()->create([
        'name' => 'Disabled Store',
    ]);

    $page = loginAndVisit($this->user, '/admin/retailers');

    // Verify all retailers are visible initially
    $page->assertSee('Active Store')
        ->assertSee('Paused Store')
        ->assertSee('Disabled Store')
        ->assertNoJavascriptErrors();

    // Click on the status filter dropdown and select 'Active'
    $page->click('button:has-text("All")')
        ->waitFor('[role="listbox"]')
        ->click('[role="option"]:has-text("Active")')
        ->waitForNavigation();

    // Verify only active retailer is shown
    $page->assertSee('Active Store')
        ->assertDontSee('Paused Store')
        ->assertDontSee('Disabled Store');
});

test('retailers can be searched', function () {
    Retailer::factory()->create(['name' => 'Tesco Supermarket', 'slug' => 'tesco']);
    Retailer::factory()->create(['name' => 'Asda Stores', 'slug' => 'asda']);
    Retailer::factory()->create(['name' => 'Sainsburys', 'slug' => 'sainsburys']);

    $page = loginAndVisit($this->user, '/admin/retailers');

    // Verify all retailers are visible initially
    $page->assertSee('Tesco Supermarket')
        ->assertSee('Asda Stores')
        ->assertSee('Sainsburys')
        ->assertNoJavascriptErrors();

    // Type in the search box
    $page->type('input[type="search"]', 'tesco')
        ->waitForNavigation();

    // Verify only matching retailer is shown
    $page->assertSee('Tesco Supermarket')
        ->assertDontSee('Asda Stores')
        ->assertDontSee('Sainsburys');
});

test('create retailer page loads', function () {
    $page = loginAndVisit($this->user, '/admin/retailers/create');

    $page->assertSee('Create Retailer')
        ->assertSee('Add a new retailer to the crawling system')
        ->assertSee('Retailer Details')
        ->assertSee('Basic Information')
        ->assertSee('Crawler Configuration')
        ->assertNoJavascriptErrors();

    // Verify form fields are present
    $page->assertPresent('input[name="name"]')
        ->assertPresent('input[name="slug"]')
        ->assertPresent('input[name="base_url"]')
        ->assertPresent('input[name="rate_limit_ms"]');
});

test('retailer can be created', function () {
    $page = loginAndVisit($this->user, '/admin/retailers/create');

    // Fill in the form
    $page->type('input[name="name"]', 'New Test Retailer')
        ->type('input[name="base_url"]', 'https://newretailer.example.com')
        ->clear('input[name="rate_limit_ms"]')
        ->type('input[name="rate_limit_ms"]', '1500')
        ->assertNoJavascriptErrors();

    // Select a crawler class from dropdown
    $page->click('button:has-text("Select a crawler")')
        ->waitFor('[role="listbox"]')
        ->click('[role="option"]:first-child');

    // Select status from dropdown (should default to Active)
    $page->click('button:has-text("Active")')
        ->waitFor('[role="listbox"]')
        ->click('[role="option"]:has-text("Active")');

    // Submit the form
    $page->click('button[type="submit"]:has-text("Create Retailer")')
        ->waitForNavigation();

    // Verify redirect to edit page with success message
    $page->assertUrlContains('/admin/retailers/')
        ->assertUrlContains('/edit')
        ->assertSee('Retailer created successfully');

    // Verify the retailer was created in the database
    $retailer = Retailer::where('name', 'New Test Retailer')->first();
    expect($retailer)->not->toBeNull()
        ->and($retailer->base_url)->toBe('https://newretailer.example.com')
        ->and($retailer->rate_limit_ms)->toBe(1500);
});

test('edit retailer page loads', function () {
    $retailer = Retailer::factory()->create([
        'name' => 'Test Retailer For Edit',
        'slug' => 'test-retailer-edit',
        'base_url' => 'https://test-edit.example.com',
        'status' => RetailerStatus::Active,
        'rate_limit_ms' => 2000,
    ]);

    $page = loginAndVisit($this->user, "/admin/retailers/{$retailer->id}/edit");

    $page->assertSee('Test Retailer For Edit')
        ->assertSee('Active')
        ->assertSee('Retailer Details')
        ->assertSee('Update retailer information and crawler configuration')
        ->assertNoJavascriptErrors();

    // Verify form is pre-populated with retailer data
    $page->assertValue('input[name="name"]', 'Test Retailer For Edit')
        ->assertValue('input[name="slug"]', 'test-retailer-edit')
        ->assertValue('input[name="base_url"]', 'https://test-edit.example.com')
        ->assertValue('input[name="rate_limit_ms"]', '2000');
});

test('retailer can be updated', function () {
    $retailer = Retailer::factory()->create([
        'name' => 'Original Retailer Name',
        'slug' => 'original-retailer',
        'base_url' => 'https://original.example.com',
        'status' => RetailerStatus::Active,
        'rate_limit_ms' => 1000,
        'crawler_class' => 'App\\Crawler\\Scrapers\\TescoCrawler',
    ]);

    $page = loginAndVisit($this->user, "/admin/retailers/{$retailer->id}/edit");

    // Clear and update form fields
    $page->clear('input[name="name"]')
        ->type('input[name="name"]', 'Updated Retailer Name')
        ->clear('input[name="base_url"]')
        ->type('input[name="base_url"]', 'https://updated.example.com')
        ->clear('input[name="rate_limit_ms"]')
        ->type('input[name="rate_limit_ms"]', '2500')
        ->assertNoJavascriptErrors();

    // Submit the form
    $page->click('button[type="submit"]:has-text("Save Changes")')
        ->waitForNavigation();

    // Verify success message
    $page->assertSee('Retailer updated successfully');

    // Verify the retailer was updated in the database
    $retailer->refresh();
    expect($retailer->name)->toBe('Updated Retailer Name')
        ->and($retailer->base_url)->toBe('https://updated.example.com')
        ->and($retailer->rate_limit_ms)->toBe(2500);
});

test('retailer status actions work - pause and resume', function () {
    $retailer = Retailer::factory()->create([
        'name' => 'Status Test Retailer',
        'status' => RetailerStatus::Active,
    ]);

    $page = loginAndVisit($this->user, '/admin/retailers');

    // Find the retailer row and click the actions menu
    $page->assertSee('Status Test Retailer')
        ->click('tr:has-text("Status Test Retailer") button[aria-haspopup="menu"]')
        ->waitFor('[role="menuitem"]');

    // Click Pause option
    $page->click('[role="menuitem"]:has-text("Pause")')
        ->waitFor('[role="dialog"]');

    // Verify pause dialog is shown
    $page->assertSee('Pause Retailer')
        ->assertSee('Temporarily pause crawling');

    // Select duration and confirm
    $page->click('button:has-text("Pause Retailer")')
        ->waitFor('tr:has-text("Status Test Retailer"):has-text("Paused")');

    // Verify retailer is now paused
    $retailer->refresh();
    expect($retailer->status)->toBe(RetailerStatus::Paused);

    // Now test resume - click actions menu again
    $page->click('tr:has-text("Status Test Retailer") button[aria-haspopup="menu"]')
        ->waitFor('[role="menuitem"]');

    // Click Resume option
    $page->click('[role="menuitem"]:has-text("Resume")')
        ->waitFor('tr:has-text("Status Test Retailer"):has-text("Active")');

    // Verify retailer is now active again
    $retailer->refresh();
    expect($retailer->status)->toBe(RetailerStatus::Active);
});

test('retailer status actions work - disable and enable', function () {
    $retailer = Retailer::factory()->create([
        'name' => 'Disable Test Retailer',
        'status' => RetailerStatus::Active,
    ]);

    $page = loginAndVisit($this->user, '/admin/retailers');

    // Find the retailer row and click the actions menu
    $page->assertSee('Disable Test Retailer')
        ->click('tr:has-text("Disable Test Retailer") button[aria-haspopup="menu"]')
        ->waitFor('[role="menuitem"]');

    // Click Disable option
    $page->click('[role="menuitem"]:has-text("Disable")')
        ->waitFor('[role="dialog"]');

    // Verify disable dialog is shown
    $page->assertSee('Disable Retailer')
        ->assertSee('Disable crawling');

    // Confirm disable
    $page->click('button:has-text("Disable Retailer")')
        ->waitFor('tr:has-text("Disable Test Retailer"):has-text("Disabled")');

    // Verify retailer is now disabled
    $retailer->refresh();
    expect($retailer->status)->toBe(RetailerStatus::Disabled);

    // Now test enable - click actions menu again
    $page->click('tr:has-text("Disable Test Retailer") button[aria-haspopup="menu"]')
        ->waitFor('[role="menuitem"]');

    // Click Enable option
    $page->click('[role="menuitem"]:has-text("Enable")')
        ->waitFor('tr:has-text("Disable Test Retailer"):has-text("Active")');

    // Verify retailer is now active again
    $retailer->refresh();
    expect($retailer->status)->toBe(RetailerStatus::Active);
});

test('retailer connection test button works', function () {
    $retailer = Retailer::factory()->create([
        'name' => 'Connection Test Retailer',
        'crawler_class' => null, // No crawler class configured
    ]);

    $page = loginAndVisit($this->user, "/admin/retailers/{$retailer->id}/edit");

    // Verify the Test Connection button is present but disabled (no crawler configured)
    $page->assertSee('Test Connection')
        ->assertDisabled('button:has-text("Test Connection")');

    // Select a crawler class
    $page->click('button:has-text("Select a crawler")')
        ->waitFor('[role="listbox"]')
        ->click('[role="option"]:first-child');

    // Now the button should be enabled
    $page->assertNotDisabled('button:has-text("Test Connection")');

    // Click test connection
    $page->click('button:has-text("Test Connection")')
        ->waitFor('.bg-green-50, .bg-red-50');

    // Verify a result message is shown (either success or failure)
    $page->assertSee('Connection');
});
