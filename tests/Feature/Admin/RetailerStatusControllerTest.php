<?php

declare(strict_types=1);

use App\Enums\RetailerStatus;
use App\Events\RetailerStatusChanged;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('pause endpoint', function () {
    test('requires authentication', function () {
        $retailer = Retailer::factory()->create();

        $response = $this->postJson("/api/v1/admin/retailers/{$retailer->id}/pause");

        $response->assertUnauthorized();
    });

    test('pauses retailer with default duration', function () {
        Event::fake();
        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'paused_until' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/admin/retailers/{$retailer->id}/pause");

        $response->assertSuccessful()
            ->assertJsonPath('message', 'Retailer paused successfully')
            ->assertJsonPath('retailer.id', $retailer->id)
            ->assertJsonPath('retailer.status', 'paused');

        $retailer->refresh();
        expect($retailer->status)->toBe(RetailerStatus::Paused)
            ->and($retailer->paused_until)->not->toBeNull()
            ->and($retailer->paused_until->isFuture())->toBeTrue();

        Event::assertDispatched(RetailerStatusChanged::class);
    });

    test('pauses retailer with custom duration', function () {
        Event::fake();
        $retailer = Retailer::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/admin/retailers/{$retailer->id}/pause", [
                'duration_minutes' => 120,
                'reason' => 'Testing pause',
            ]);

        $response->assertSuccessful();

        $retailer->refresh();
        $minutesUntilResume = now()->diffInMinutes($retailer->paused_until, false);
        expect($retailer->status)->toBe(RetailerStatus::Paused)
            ->and($retailer->paused_until)->not->toBeNull()
            ->and($minutesUntilResume)->toBeGreaterThanOrEqual(119)
            ->and($minutesUntilResume)->toBeLessThanOrEqual(121);

        Event::assertDispatched(RetailerStatusChanged::class);
    });

    test('validates duration_minutes is numeric', function () {
        $retailer = Retailer::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/admin/retailers/{$retailer->id}/pause", [
                'duration_minutes' => 'invalid',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['duration_minutes']);
    });

    test('validates duration_minutes minimum value', function () {
        $retailer = Retailer::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/admin/retailers/{$retailer->id}/pause", [
                'duration_minutes' => 0,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['duration_minutes']);
    });

    test('validates duration_minutes maximum value', function () {
        $retailer = Retailer::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/admin/retailers/{$retailer->id}/pause", [
                'duration_minutes' => 50000,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['duration_minutes']);
    });

    test('validates reason maximum length', function () {
        $retailer = Retailer::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/admin/retailers/{$retailer->id}/pause", [
                'reason' => str_repeat('a', 501),
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    });
});

describe('resume endpoint', function () {
    test('requires authentication', function () {
        $retailer = Retailer::factory()->create();

        $response = $this->postJson("/api/v1/admin/retailers/{$retailer->id}/resume");

        $response->assertUnauthorized();
    });

    test('resumes paused retailer', function () {
        Event::fake();
        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Paused,
            'paused_until' => now()->addHours(2),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/admin/retailers/{$retailer->id}/resume");

        $response->assertSuccessful()
            ->assertJsonPath('message', 'Retailer resumed successfully')
            ->assertJsonPath('retailer.id', $retailer->id)
            ->assertJsonPath('retailer.status', 'active');

        $retailer->refresh();
        expect($retailer->status)->toBe(RetailerStatus::Active)
            ->and($retailer->paused_until)->toBeNull();

        Event::assertDispatched(RetailerStatusChanged::class);
    });

    test('can resume retailer that is not paused', function () {
        Event::fake();
        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'paused_until' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/admin/retailers/{$retailer->id}/resume");

        $response->assertSuccessful();

        Event::assertDispatched(RetailerStatusChanged::class);
    });
});

describe('disable endpoint', function () {
    test('requires authentication', function () {
        $retailer = Retailer::factory()->create();

        $response = $this->postJson("/api/v1/admin/retailers/{$retailer->id}/disable");

        $response->assertUnauthorized();
    });

    test('disables active retailer', function () {
        Event::fake();
        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/admin/retailers/{$retailer->id}/disable", [
                'reason' => 'Testing disable',
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('message', 'Retailer disabled successfully')
            ->assertJsonPath('retailer.id', $retailer->id)
            ->assertJsonPath('retailer.status', 'disabled');

        $retailer->refresh();
        expect($retailer->status)->toBe(RetailerStatus::Disabled)
            ->and($retailer->paused_until)->toBeNull();

        Event::assertDispatched(RetailerStatusChanged::class);
    });

    test('clears pause when disabling', function () {
        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'paused_until' => now()->addHours(2),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/admin/retailers/{$retailer->id}/disable");

        $response->assertSuccessful();

        $retailer->refresh();
        expect($retailer->status)->toBe(RetailerStatus::Disabled)
            ->and($retailer->paused_until)->toBeNull();
    });

    test('validates reason maximum length', function () {
        $retailer = Retailer::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/admin/retailers/{$retailer->id}/disable", [
                'reason' => str_repeat('a', 501),
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    });
});

describe('enable endpoint', function () {
    test('requires authentication', function () {
        $retailer = Retailer::factory()->create();

        $response = $this->postJson("/api/v1/admin/retailers/{$retailer->id}/enable");

        $response->assertUnauthorized();
    });

    test('enables disabled retailer', function () {
        Event::fake();
        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Disabled,
            'consecutive_failures' => 5,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/admin/retailers/{$retailer->id}/enable");

        $response->assertSuccessful()
            ->assertJsonPath('message', 'Retailer enabled successfully')
            ->assertJsonPath('retailer.id', $retailer->id)
            ->assertJsonPath('retailer.status', 'active');

        $retailer->refresh();
        expect($retailer->status)->toBe(RetailerStatus::Active)
            ->and($retailer->paused_until)->toBeNull()
            ->and($retailer->consecutive_failures)->toBe(0);

        Event::assertDispatched(RetailerStatusChanged::class);
    });

    test('clears pause and resets failures when enabling', function () {
        $retailer = Retailer::factory()->create([
            'status' => RetailerStatus::Disabled,
            'paused_until' => now()->addHours(2),
            'consecutive_failures' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/admin/retailers/{$retailer->id}/enable");

        $response->assertSuccessful();

        $retailer->refresh();
        expect($retailer->status)->toBe(RetailerStatus::Active)
            ->and($retailer->paused_until)->toBeNull()
            ->and($retailer->consecutive_failures)->toBe(0);
    });
});

describe('response format', function () {
    test('includes all expected retailer fields', function () {
        $retailer = Retailer::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/admin/retailers/{$retailer->id}/pause");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'message',
                'retailer' => [
                    'id',
                    'name',
                    'slug',
                    'status',
                    'status_label',
                    'status_color',
                    'status_description',
                    'consecutive_failures',
                    'last_failure_at',
                    'paused_until',
                    'last_crawled_at',
                    'is_paused',
                    'is_available_for_crawling',
                ],
            ]);
    });
});
