<?php

declare(strict_types=1);

use App\Models\PriceAlert;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

describe('GET /api/v1/alerts', function () {
    it('requires authentication', function () {
        $response = getJson(route('api.v1.alerts.index'));

        $response->assertUnauthorized();
    });

    it('returns the authenticated users price alerts', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $alert = PriceAlert::factory()->create(['user_id' => $user->id]);
        PriceAlert::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);

        $response = getJson(route('api.v1.alerts.index'));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $alert->id);
    });

    it('includes product data in the response', function () {
        $user = User::factory()->create();
        $product = Product::factory()->create(['name' => 'Test Product']);
        PriceAlert::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        Sanctum::actingAs($user);

        $response = getJson(route('api.v1.alerts.index'));

        $response->assertSuccessful()
            ->assertJsonPath('data.0.product.name', 'Test Product');
    });

    it('returns alerts in descending order by creation date', function () {
        $user = User::factory()->create();
        $oldAlert = PriceAlert::factory()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays(2),
        ]);
        $newAlert = PriceAlert::factory()->create([
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = getJson(route('api.v1.alerts.index'));

        $response->assertSuccessful()
            ->assertJsonPath('data.0.id', $newAlert->id)
            ->assertJsonPath('data.1.id', $oldAlert->id);
    });

    it('respects per_page parameter', function () {
        $user = User::factory()->create();
        PriceAlert::factory()->count(5)->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = getJson(route('api.v1.alerts.index', ['per_page' => 2]));

        $response->assertSuccessful()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2);
    });

    it('limits per_page to 100', function () {
        $user = User::factory()->create();
        PriceAlert::factory()->count(5)->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = getJson(route('api.v1.alerts.index', ['per_page' => 200]));

        $response->assertSuccessful()
            ->assertJsonPath('meta.per_page', 100);
    });
});

describe('POST /api/v1/alerts', function () {
    it('requires authentication', function () {
        $response = postJson(route('api.v1.alerts.store'), [
            'product_id' => 1,
            'target_price_pence' => 1000,
        ]);

        $response->assertUnauthorized();
    });

    it('creates a new price alert', function () {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        Sanctum::actingAs($user);

        $response = postJson(route('api.v1.alerts.store'), [
            'product_id' => $product->id,
            'target_price_pence' => 1500,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.target_price_pence', 1500)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('price_alerts', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'target_price_pence' => 1500,
            'is_active' => true,
        ]);
    });

    it('includes product data in the response', function () {
        $user = User::factory()->create();
        $product = Product::factory()->create(['name' => 'My Product']);

        Sanctum::actingAs($user);

        $response = postJson(route('api.v1.alerts.store'), [
            'product_id' => $product->id,
            'target_price_pence' => 1500,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.product.name', 'My Product');
    });

    it('validates product_id is required', function () {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = postJson(route('api.v1.alerts.store'), [
            'target_price_pence' => 1500,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id']);
    });

    it('validates product_id exists', function () {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = postJson(route('api.v1.alerts.store'), [
            'product_id' => 99999,
            'target_price_pence' => 1500,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id']);
    });

    it('validates target_price_pence is required', function () {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        Sanctum::actingAs($user);

        $response = postJson(route('api.v1.alerts.store'), [
            'product_id' => $product->id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['target_price_pence']);
    });

    it('validates target_price_pence is at least 1', function () {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        Sanctum::actingAs($user);

        $response = postJson(route('api.v1.alerts.store'), [
            'product_id' => $product->id,
            'target_price_pence' => 0,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['target_price_pence']);
    });

    it('prevents duplicate alerts for the same product', function () {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        PriceAlert::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);

        Sanctum::actingAs($user);

        $response = postJson(route('api.v1.alerts.store'), [
            'product_id' => $product->id,
            'target_price_pence' => 1500,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id'])
            ->assertJsonPath('errors.product_id.0', 'You already have a price alert for this product.');
    });

    it('allows different users to set alerts for the same product', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $product = Product::factory()->create();

        PriceAlert::factory()->create([
            'user_id' => $user1->id,
            'product_id' => $product->id,
        ]);

        Sanctum::actingAs($user2);

        $response = postJson(route('api.v1.alerts.store'), [
            'product_id' => $product->id,
            'target_price_pence' => 1500,
        ]);

        $response->assertSuccessful();
    });
});

describe('DELETE /api/v1/alerts/{alert}', function () {
    it('requires authentication', function () {
        $alert = PriceAlert::factory()->create();

        $response = deleteJson(route('api.v1.alerts.destroy', $alert));

        $response->assertUnauthorized();
    });

    it('deletes the users own alert', function () {
        $user = User::factory()->create();
        $alert = PriceAlert::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = deleteJson(route('api.v1.alerts.destroy', $alert));

        $response->assertSuccessful()
            ->assertJson(['message' => 'Price alert deleted successfully.']);

        $this->assertDatabaseMissing('price_alerts', ['id' => $alert->id]);
    });

    it('prevents deleting another users alert', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $alert = PriceAlert::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);

        $response = deleteJson(route('api.v1.alerts.destroy', $alert));

        $response->assertForbidden();

        $this->assertDatabaseHas('price_alerts', ['id' => $alert->id]);
    });

    it('returns 404 for non-existent alert', function () {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = deleteJson(route('api.v1.alerts.destroy', 99999));

        $response->assertNotFound();
    });
});
