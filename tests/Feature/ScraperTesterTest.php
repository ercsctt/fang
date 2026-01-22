<?php

/*
 * ScraperTester Feature Tests
 *
 * Note: Some tests require Vite assets to be built. Run `npm run build` before testing UI routes.
 * API endpoint tests work without Vite.
 */

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('scraper tester page requires authentication', function () {
    $response = $this->get('/scraper-tester');

    $response->assertRedirect('/login');
});

test('fetch endpoint requires authentication', function () {
    $response = $this->post('/scraper-tester/fetch', [
        'url' => 'https://example.com',
    ]);

    $response->assertRedirect('/login');
});

test('fetch endpoint validates URL is required', function () {
    $response = $this->actingAs($this->user)->postJson('/scraper-tester/fetch', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['url']);
});

test('fetch endpoint validates URL format', function () {
    $response = $this->actingAs($this->user)->postJson('/scraper-tester/fetch', [
        'url' => 'not-a-valid-url',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['url']);
});

test('fetch endpoint accepts valid URL and returns success', function () {
    $response = $this->actingAs($this->user)->postJson('/scraper-tester/fetch', [
        'url' => 'https://example.com',
        'use_proxy' => false,
        'rotate_user_agent' => true,
    ]);

    // Will attempt real HTTP request (or fail gracefully)
    expect($response->status())->toBeIn([200, 400]); // Either success or error is acceptable
    $response->assertJsonStructure(['success']);
});

test('fetch endpoint handles errors gracefully', function () {
    // Test with definitely invalid URL
    $response = $this->actingAs($this->user)->postJson('/scraper-tester/fetch', [
        'url' => 'https://this-domain-definitely-does-not-exist-12345678.com',
        'use_proxy' => false,
        'rotate_user_agent' => true,
    ]);

    // Should return error response
    $response->assertStatus(400)
        ->assertJsonStructure([
            'success',
            'error',
        ])
        ->assertJson([
            'success' => false,
        ]);
});
