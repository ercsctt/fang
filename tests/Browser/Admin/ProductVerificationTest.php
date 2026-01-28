<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductListing;
use App\Models\ProductListingMatch;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);
});

/**
 * Helper function to login and visit product verification page.
 */
function loginAndVisitVerification(string $path = ''): mixed
{
    $url = '/admin/product-verification'.($path ? '/'.ltrim($path, '/') : '');

    return visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('[data-test="login-button"]')
        ->waitForUrl('/dashboard')
        ->navigate($url);
}

test('verification index page loads', function () {
    $page = loginAndVisitVerification();

    $page->assertSee('Product Verification')
        ->assertSee('Review and verify product listing matches')
        ->assertSee('Verification Queue')
        ->assertNoJavascriptErrors();
});

test('verification stats display correct counts', function () {
    $retailer = Retailer::factory()->create();
    $product = Product::factory()->create();

    // Create pending matches
    ProductListingMatch::factory()
        ->count(5)
        ->pending()
        ->for($product)
        ->create([
            'product_listing_id' => ProductListing::factory()->for($retailer),
        ]);

    // Create approved matches
    ProductListingMatch::factory()
        ->count(3)
        ->approved($this->user)
        ->for($product)
        ->create([
            'product_listing_id' => ProductListing::factory()->for($retailer),
        ]);

    // Create rejected matches
    ProductListingMatch::factory()
        ->count(2)
        ->rejected($this->user)
        ->for($product)
        ->create([
            'product_listing_id' => ProductListing::factory()->for($retailer),
        ]);

    // Create high confidence pending matches
    ProductListingMatch::factory()
        ->count(4)
        ->pending()
        ->highConfidence()
        ->for($product)
        ->create([
            'product_listing_id' => ProductListing::factory()->for($retailer),
        ]);

    $page = loginAndVisitVerification();

    // KPI cards should display correct counts
    $page->assertSee('Pending Review')
        ->assertSee('9') // 5 + 4 high confidence pending
        ->assertSee('Approved')
        ->assertSee('3')
        ->assertSee('Rejected')
        ->assertSee('2')
        ->assertSee('High Confidence')
        ->assertSee('4');
});

test('verification table displays matches', function () {
    $retailer = Retailer::factory()->create(['name' => 'Test Retailer']);
    $product = Product::factory()->create(['name' => 'Test Product ABC']);
    $listing = ProductListing::factory()
        ->for($retailer)
        ->create(['title' => 'Test Listing XYZ']);

    ProductListingMatch::factory()
        ->pending()
        ->for($product)
        ->create([
            'product_listing_id' => $listing->id,
            'confidence_score' => 85.5,
        ]);

    $page = loginAndVisitVerification();

    $page->assertSee('Verification Queue')
        ->assertSee('Test Listing XYZ')
        ->assertSee('Test Product ABC')
        ->assertSee('Test Retailer')
        ->assertSee('85.5%')
        ->assertSee('pending')
        ->assertNoJavascriptErrors();
});

test('status filter works', function () {
    $retailer = Retailer::factory()->create();
    $product = Product::factory()->create();

    $pendingListing = ProductListing::factory()
        ->for($retailer)
        ->create(['title' => 'Pending Match Listing']);
    ProductListingMatch::factory()
        ->pending()
        ->for($product)
        ->create(['product_listing_id' => $pendingListing->id]);

    $approvedListing = ProductListing::factory()
        ->for($retailer)
        ->create(['title' => 'Approved Match Listing']);
    ProductListingMatch::factory()
        ->approved($this->user)
        ->for($product)
        ->create(['product_listing_id' => $approvedListing->id]);

    $rejectedListing = ProductListing::factory()
        ->for($retailer)
        ->create(['title' => 'Rejected Match Listing']);
    ProductListingMatch::factory()
        ->rejected($this->user)
        ->for($product)
        ->create(['product_listing_id' => $rejectedListing->id]);

    // Index defaults to pending
    $page = loginAndVisitVerification();

    $page->assertSee('Pending Match Listing')
        ->assertDontSee('Approved Match Listing')
        ->assertDontSee('Rejected Match Listing');

    // Switch to approved filter
    $page->click('button:has-text("Pending")')
        ->waitFor('[role="listbox"]')
        ->click('[role="option"]:has-text("Approved")')
        ->waitForNavigation();

    $page->assertSee('Approved Match Listing')
        ->assertDontSee('Pending Match Listing')
        ->assertDontSee('Rejected Match Listing');

    // Switch to rejected filter
    $page->click('button:has-text("Approved")')
        ->waitFor('[role="listbox"]')
        ->click('[role="option"]:has-text("Rejected")')
        ->waitForNavigation();

    $page->assertSee('Rejected Match Listing')
        ->assertDontSee('Pending Match Listing')
        ->assertDontSee('Approved Match Listing');

    // Switch to all filter
    $page->click('button:has-text("Rejected")')
        ->waitFor('[role="listbox"]')
        ->click('[role="option"]:has-text("All")')
        ->waitForNavigation();

    $page->assertSee('Pending Match Listing')
        ->assertSee('Approved Match Listing')
        ->assertSee('Rejected Match Listing');
});

test('verification show page loads', function () {
    $retailer = Retailer::factory()->create(['name' => 'Show Page Retailer']);
    $product = Product::factory()->create([
        'name' => 'Show Page Product',
        'brand' => 'Test Brand',
    ]);
    $listing = ProductListing::factory()
        ->for($retailer)
        ->create([
            'title' => 'Show Page Listing',
            'brand' => 'Listing Brand',
            'price_pence' => 1999,
        ]);

    $match = ProductListingMatch::factory()
        ->pending()
        ->fuzzy()
        ->for($product)
        ->create([
            'product_listing_id' => $listing->id,
            'confidence_score' => 78.3,
        ]);

    $page = loginAndVisitVerification((string) $match->id);

    $page->assertSee("Verify Match #{$match->id}")
        ->assertSee('Review the product match and take action')
        ->assertSee('Show Page Listing')
        ->assertSee('Show Page Product')
        ->assertSee('Show Page Retailer')
        ->assertSee('78.3%')
        ->assertSee('Fuzzy Match')
        ->assertSee('pending')
        ->assertNoJavascriptErrors();
});

test('match can be approved', function () {
    $retailer = Retailer::factory()->create();
    $product = Product::factory()->create(['name' => 'Approval Test Product']);
    $listing = ProductListing::factory()
        ->for($retailer)
        ->create(['title' => 'Approval Test Listing']);

    $match = ProductListingMatch::factory()
        ->pending()
        ->for($product)
        ->create(['product_listing_id' => $listing->id]);

    $page = loginAndVisitVerification((string) $match->id);

    // Verify we're on the show page and actions are visible
    $page->assertSee('Actions')
        ->assertSee('Approve Match');

    // Click the approve button
    $page->click('button:has-text("Approve Match")')
        ->waitForNavigation();

    // Verify success message
    $page->assertSee('Match approved successfully');

    // Verify the match status in database
    $match->refresh();
    expect($match->status->value)->toBe('approved')
        ->and($match->verified_by)->toBe($this->user->id)
        ->and($match->verified_at)->not->toBeNull();
});

test('match can be rejected with reason', function () {
    $retailer = Retailer::factory()->create();
    $product = Product::factory()->create(['name' => 'Rejection Test Product']);
    $listing = ProductListing::factory()
        ->for($retailer)
        ->create(['title' => 'Rejection Test Listing']);

    $match = ProductListingMatch::factory()
        ->pending()
        ->for($product)
        ->create(['product_listing_id' => $listing->id]);

    $page = loginAndVisitVerification((string) $match->id);

    // Verify we're on the show page
    $page->assertSee('Actions')
        ->assertSee('Reject Match');

    // Enter a rejection reason
    $page->type('textarea#rejection-reason', 'Products do not match - different weights');

    // Click the reject button
    $page->click('button:has-text("Reject Match")')
        ->waitForNavigation();

    // Verify success message
    $page->assertSee('Match rejected successfully');

    // Verify the match status in database
    $match->refresh();
    expect($match->status->value)->toBe('rejected')
        ->and($match->verified_by)->toBe($this->user->id)
        ->and($match->verified_at)->not->toBeNull()
        ->and($match->rejection_reason)->toBe('Products do not match - different weights');
});

test('bulk approve high confidence matches', function () {
    $retailer = Retailer::factory()->create();
    $product = Product::factory()->create();

    // Create multiple high confidence pending matches
    ProductListingMatch::factory()
        ->count(5)
        ->pending()
        ->highConfidence()
        ->for($product)
        ->create([
            'product_listing_id' => ProductListing::factory()->for($retailer),
        ]);

    $page = loginAndVisitVerification();

    // Verify bulk approve button shows count
    $page->assertSee('Bulk Approve (5)');

    // Click bulk approve
    $page->click('button:has-text("Bulk Approve")')
        ->waitFor('.text-green-600, .text-green-400');

    // Wait for page reload and verify stats updated
    $page->waitForText('Bulk Approve')
        ->assertNoJavascriptErrors();

    // Verify matches were approved in database
    $approvedCount = ProductListingMatch::approved()->count();
    expect($approvedCount)->toBe(5);
});

test('pagination works', function () {
    $retailer = Retailer::factory()->create();
    $product = Product::factory()->create();

    // Create 25 pending matches (more than one page of 20)
    ProductListingMatch::factory()
        ->count(25)
        ->pending()
        ->for($product)
        ->create([
            'product_listing_id' => ProductListing::factory()->for($retailer),
        ]);

    $page = loginAndVisitVerification();

    // Verify pagination info is shown
    $page->assertSee('Showing 1 to 20 of 25 results')
        ->assertSee('Next')
        ->assertSee('Previous');

    // Click next page
    $page->click('button:has-text("Next")')
        ->waitForNavigation();

    // Verify we're on page 2
    $page->assertSee('Showing 21 to 25 of 25 results')
        ->assertUrlContains('page=2');

    // Click previous page
    $page->click('button:has-text("Previous")')
        ->waitForNavigation();

    // Verify we're back on page 1
    $page->assertSee('Showing 1 to 20 of 25 results')
        ->assertDontSee('page=2');
});

test('quick approve from table works', function () {
    $retailer = Retailer::factory()->create();
    $product = Product::factory()->create(['name' => 'Quick Approve Product']);
    $listing = ProductListing::factory()
        ->for($retailer)
        ->create(['title' => 'Quick Approve Listing']);

    $match = ProductListingMatch::factory()
        ->pending()
        ->for($product)
        ->create(['product_listing_id' => $listing->id]);

    $page = loginAndVisitVerification();

    // Verify the listing is shown with pending status
    $page->assertSee('Quick Approve Listing')
        ->assertSee('pending');

    // Click the approve icon button in the table row
    $page->click('tr:has-text("Quick Approve Listing") button[title="Approve"]')
        ->waitForNavigation();

    // Verify the match was approved
    $match->refresh();
    expect($match->status->value)->toBe('approved');
});

test('quick reject from table works', function () {
    $retailer = Retailer::factory()->create();
    $product = Product::factory()->create(['name' => 'Quick Reject Product']);
    $listing = ProductListing::factory()
        ->for($retailer)
        ->create(['title' => 'Quick Reject Listing']);

    $match = ProductListingMatch::factory()
        ->pending()
        ->for($product)
        ->create(['product_listing_id' => $listing->id]);

    $page = loginAndVisitVerification();

    // Verify the listing is shown with pending status
    $page->assertSee('Quick Reject Listing')
        ->assertSee('pending');

    // Click the reject icon button in the table row
    $page->click('tr:has-text("Quick Reject Listing") button[title="Reject"]')
        ->waitForNavigation();

    // Verify the match was rejected
    $match->refresh();
    expect($match->status->value)->toBe('rejected');
});

test('clicking table row navigates to show page', function () {
    $retailer = Retailer::factory()->create();
    $product = Product::factory()->create(['name' => 'Navigate Test Product']);
    $listing = ProductListing::factory()
        ->for($retailer)
        ->create(['title' => 'Navigate Test Listing']);

    $match = ProductListingMatch::factory()
        ->pending()
        ->for($product)
        ->create(['product_listing_id' => $listing->id]);

    $page = loginAndVisitVerification();

    // Click the view details button
    $page->click('tr:has-text("Navigate Test Listing") button[title="View details"]')
        ->waitForNavigation();

    // Verify we're on the show page
    $page->assertUrlContains("/admin/product-verification/{$match->id}")
        ->assertSee("Verify Match #{$match->id}");
});

test('show page displays match details correctly', function () {
    $retailer = Retailer::factory()->create(['name' => 'Details Retailer']);
    $product = Product::factory()->create([
        'name' => 'Details Test Product',
        'brand' => 'Details Brand',
        'description' => 'Product description text',
    ]);
    $listing = ProductListing::factory()
        ->for($retailer)
        ->create([
            'title' => 'Details Test Listing',
            'brand' => 'Listing Brand',
            'description' => 'Listing description text',
            'price_pence' => 2499,
        ]);

    $match = ProductListingMatch::factory()
        ->exact()
        ->pending()
        ->for($product)
        ->create([
            'product_listing_id' => $listing->id,
            'confidence_score' => 92.5,
        ]);

    $page = loginAndVisitVerification((string) $match->id);

    // Verify match details section
    $page->assertSee('Match Details')
        ->assertSee('Match Type')
        ->assertSee('Exact Match')
        ->assertSee('Matched At')
        ->assertNoJavascriptErrors();

    // Verify product listing card
    $page->assertSee('Product Listing')
        ->assertSee('Details Test Listing')
        ->assertSee('Details Retailer');

    // Verify canonical product card
    $page->assertSee('Canonical Product')
        ->assertSee('Details Test Product')
        ->assertSee('Details Brand');
});
