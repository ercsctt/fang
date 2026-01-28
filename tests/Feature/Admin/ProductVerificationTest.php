<?php

declare(strict_types=1);

use App\Enums\MatchType;
use App\Enums\VerificationStatus;
use App\Models\Product;
use App\Models\ProductListingMatch;
use App\Models\User;
use App\Models\UserVerificationStat;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
});

describe('index', function () {
    test('requires authentication', function () {
        $response = $this->get('/admin/product-verification');

        $response->assertRedirect('/login');
    });

    test('displays verification queue page', function () {
        $this->actingAs($this->user);

        ProductListingMatch::factory()->pending()->count(3)->create();

        $response = $this->get('/admin/product-verification');

        $response->assertSuccessful()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Admin/ProductVerification/Index')
                    ->has('matches.data', 3)
                    ->has('stats')
            );
    });

    test('filters by status', function () {
        $this->actingAs($this->user);

        ProductListingMatch::factory()->pending()->count(2)->create();
        ProductListingMatch::factory()->approved()->count(3)->create();
        ProductListingMatch::factory()->rejected()->count(1)->create();

        $response = $this->get('/admin/product-verification?status=approved');

        $response->assertSuccessful()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Admin/ProductVerification/Index')
                    ->has('matches.data', 3)
            );
    });

    test('sorts by confidence score ascending by default', function () {
        $this->actingAs($this->user);

        ProductListingMatch::factory()->create(['confidence_score' => 90.0]);
        ProductListingMatch::factory()->create(['confidence_score' => 50.0]);
        ProductListingMatch::factory()->create(['confidence_score' => 70.0]);

        $response = $this->get('/admin/product-verification');

        $response->assertSuccessful()
            ->assertInertia(function ($page) {
                $page->component('Admin/ProductVerification/Index');
                $matches = $page->toArray()['props']['matches']['data'];
                expect($matches[0]['confidence_score'])->toBeLessThanOrEqual($matches[1]['confidence_score']);
            });
    });

    test('returns verification stats', function () {
        $this->actingAs($this->user);

        ProductListingMatch::factory()->pending()->count(5)->create();
        ProductListingMatch::factory()->approved()->count(3)->create();
        ProductListingMatch::factory()->rejected()->count(2)->create();
        ProductListingMatch::factory()->pending()->highConfidence()->count(2)->create();

        $response = $this->get('/admin/product-verification');

        $response->assertSuccessful()
            ->assertInertia(
                fn ($page) => $page
                    ->where('stats.pending', 7)
                    ->where('stats.approved', 3)
                    ->where('stats.rejected', 2)
                    ->where('stats.total', 12)
            );
    });
});

describe('show', function () {
    test('requires authentication', function () {
        $match = ProductListingMatch::factory()->create();

        $response = $this->get("/admin/product-verification/{$match->id}");

        $response->assertRedirect('/login');
    });

    test('displays match details page', function () {
        $this->actingAs($this->user);

        $match = ProductListingMatch::factory()->create();

        $response = $this->get("/admin/product-verification/{$match->id}");

        $response->assertSuccessful()
            ->assertInertia(
                fn ($page) => $page
                    ->component('Admin/ProductVerification/Show')
                    ->has('match')
                    ->has('otherMatches')
                    ->has('suggestedProducts')
            );
    });

    test('returns 404 for non-existent match', function () {
        $this->actingAs($this->user);

        $response = $this->get('/admin/product-verification/99999');

        $response->assertNotFound();
    });
});

describe('approve', function () {
    test('requires authentication', function () {
        $match = ProductListingMatch::factory()->pending()->create();

        $response = $this->post("/admin/product-verification/{$match->id}/approve");

        $response->assertRedirect('/login');
    });

    test('approves a pending match', function () {
        $this->actingAs($this->user);

        $match = ProductListingMatch::factory()->pending()->create();

        $response = $this->post("/admin/product-verification/{$match->id}/approve");

        $response->assertRedirect();

        $match->refresh();
        expect($match->status)->toBe(VerificationStatus::Approved)
            ->and($match->verified_by)->toBe($this->user->id)
            ->and($match->verified_at)->not->toBeNull();
    });

    test('increments user verification stats', function () {
        $this->actingAs($this->user);

        $match = ProductListingMatch::factory()->pending()->create();

        $this->post("/admin/product-verification/{$match->id}/approve");

        $stats = UserVerificationStat::where('user_id', $this->user->id)
            ->where('date', today())
            ->first();

        expect($stats)->not->toBeNull()
            ->and($stats->matches_approved)->toBe(1);
    });

    test('cannot approve already approved match', function () {
        $this->actingAs($this->user);

        $match = ProductListingMatch::factory()->approved()->create();

        $response = $this->post("/admin/product-verification/{$match->id}/approve");

        $response->assertForbidden();
    });

    test('cannot approve rejected match', function () {
        $this->actingAs($this->user);

        $match = ProductListingMatch::factory()->rejected()->create();

        $response = $this->post("/admin/product-verification/{$match->id}/approve");

        $response->assertForbidden();
    });
});

describe('reject', function () {
    test('requires authentication', function () {
        $match = ProductListingMatch::factory()->pending()->create();

        $response = $this->post("/admin/product-verification/{$match->id}/reject");

        $response->assertRedirect('/login');
    });

    test('rejects a pending match', function () {
        $this->actingAs($this->user);

        $match = ProductListingMatch::factory()->pending()->create();

        $response = $this->post("/admin/product-verification/{$match->id}/reject", [
            'reason' => 'Incorrect product match',
        ]);

        $response->assertRedirect();

        $match->refresh();
        expect($match->status)->toBe(VerificationStatus::Rejected)
            ->and($match->verified_by)->toBe($this->user->id)
            ->and($match->rejection_reason)->toBe('Incorrect product match');
    });

    test('rejects without reason', function () {
        $this->actingAs($this->user);

        $match = ProductListingMatch::factory()->pending()->create();

        $response = $this->post("/admin/product-verification/{$match->id}/reject");

        $response->assertRedirect();

        $match->refresh();
        expect($match->status)->toBe(VerificationStatus::Rejected)
            ->and($match->rejection_reason)->toBeNull();
    });

    test('increments user rejection stats', function () {
        $this->actingAs($this->user);

        $match = ProductListingMatch::factory()->pending()->create();

        $this->post("/admin/product-verification/{$match->id}/reject");

        $stats = UserVerificationStat::where('user_id', $this->user->id)
            ->where('date', today())
            ->first();

        expect($stats)->not->toBeNull()
            ->and($stats->matches_rejected)->toBe(1);
    });

    test('validates reason max length', function () {
        $this->actingAs($this->user);

        $match = ProductListingMatch::factory()->pending()->create();

        $response = $this->post("/admin/product-verification/{$match->id}/reject", [
            'reason' => str_repeat('a', 1001),
        ]);

        $response->assertSessionHasErrors('reason');
    });
});

describe('rematch', function () {
    test('requires authentication', function () {
        $match = ProductListingMatch::factory()->pending()->create();
        $newProduct = Product::factory()->create();

        $response = $this->post("/admin/product-verification/{$match->id}/rematch", [
            'product_id' => $newProduct->id,
        ]);

        $response->assertRedirect('/login');
    });

    test('rematches to a different product', function () {
        $this->actingAs($this->user);

        $match = ProductListingMatch::factory()->pending()->create();
        $oldProductId = $match->product_id;
        $newProduct = Product::factory()->create();

        $response = $this->post("/admin/product-verification/{$match->id}/rematch", [
            'product_id' => $newProduct->id,
        ]);

        $response->assertRedirect();

        $match->refresh();
        expect($match->product_id)->toBe($newProduct->id)
            ->and($match->status)->toBe(VerificationStatus::Approved)
            ->and($match->match_type)->toBe(MatchType::Manual)
            ->and($match->confidence_score)->toBe(100.0)
            ->and($match->verified_by)->toBe($this->user->id);
    });

    test('increments user rematch stats', function () {
        $this->actingAs($this->user);

        $match = ProductListingMatch::factory()->pending()->create();
        $newProduct = Product::factory()->create();

        $this->post("/admin/product-verification/{$match->id}/rematch", [
            'product_id' => $newProduct->id,
        ]);

        $stats = UserVerificationStat::where('user_id', $this->user->id)
            ->where('date', today())
            ->first();

        expect($stats)->not->toBeNull()
            ->and($stats->matches_rematched)->toBe(1);
    });

    test('requires valid product_id', function () {
        $this->actingAs($this->user);

        $match = ProductListingMatch::factory()->pending()->create();

        $response = $this->post("/admin/product-verification/{$match->id}/rematch", [
            'product_id' => 99999,
        ]);

        $response->assertSessionHasErrors('product_id');
    });

    test('requires different product_id', function () {
        $this->actingAs($this->user);

        $match = ProductListingMatch::factory()->pending()->create();

        $response = $this->post("/admin/product-verification/{$match->id}/rematch", [
            'product_id' => $match->product_id,
        ]);

        $response->assertSessionHasErrors('product_id');
    });
});

describe('bulk approve', function () {
    test('requires authentication', function () {
        $response = $this->postJson('/admin/product-verification/bulk-approve');

        $response->assertUnauthorized();
    });

    test('bulk approves high confidence matches', function () {
        $this->actingAs($this->user);

        ProductListingMatch::factory()->pending()->highConfidence()->count(5)->create();
        ProductListingMatch::factory()->pending()->lowConfidence()->count(3)->create();

        $response = $this->postJson('/admin/product-verification/bulk-approve', [
            'min_confidence' => 95,
            'limit' => 10,
        ]);

        $response->assertSuccessful()
            ->assertJson(['approved_count' => 5]);

        expect(ProductListingMatch::approved()->count())->toBe(5)
            ->and(ProductListingMatch::pending()->count())->toBe(3);
    });

    test('respects limit parameter', function () {
        $this->actingAs($this->user);

        ProductListingMatch::factory()->pending()->highConfidence()->count(10)->create();

        $response = $this->postJson('/admin/product-verification/bulk-approve', [
            'min_confidence' => 95,
            'limit' => 3,
        ]);

        $response->assertSuccessful()
            ->assertJson(['approved_count' => 3]);

        expect(ProductListingMatch::approved()->count())->toBe(3)
            ->and(ProductListingMatch::pending()->count())->toBe(7);
    });

    test('increments bulk approval stats', function () {
        $this->actingAs($this->user);

        ProductListingMatch::factory()->pending()->highConfidence()->count(5)->create();

        $this->postJson('/admin/product-verification/bulk-approve', [
            'min_confidence' => 95,
            'limit' => 10,
        ]);

        $stats = UserVerificationStat::where('user_id', $this->user->id)
            ->where('date', today())
            ->first();

        expect($stats)->not->toBeNull()
            ->and($stats->matches_approved)->toBe(5)
            ->and($stats->bulk_approvals)->toBe(1);
    });

    test('validates min_confidence minimum', function () {
        $this->actingAs($this->user);

        $response = $this->postJson('/admin/product-verification/bulk-approve', [
            'min_confidence' => 50,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('min_confidence');
    });

    test('validates limit maximum', function () {
        $this->actingAs($this->user);

        $response = $this->postJson('/admin/product-verification/bulk-approve', [
            'limit' => 1000,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    });
});

describe('stats endpoint', function () {
    test('requires authentication', function () {
        $response = $this->getJson('/admin/product-verification/stats');

        $response->assertUnauthorized();
    });

    test('returns user verification stats', function () {
        $this->actingAs($this->user);

        UserVerificationStat::create([
            'user_id' => $this->user->id,
            'date' => today(),
            'matches_approved' => 10,
            'matches_rejected' => 5,
            'matches_rematched' => 2,
            'bulk_approvals' => 1,
        ]);

        $response = $this->getJson('/admin/product-verification/stats');

        $response->assertSuccessful()
            ->assertJson([
                'today' => [
                    'approved' => 10,
                    'rejected' => 5,
                    'rematched' => 2,
                    'bulk_approvals' => 1,
                    'total' => 17,
                ],
            ]);
    });

    test('returns zero stats when no activity', function () {
        $this->actingAs($this->user);

        $response = $this->getJson('/admin/product-verification/stats');

        $response->assertSuccessful()
            ->assertJson([
                'today' => [
                    'approved' => 0,
                    'rejected' => 0,
                    'rematched' => 0,
                    'bulk_approvals' => 0,
                    'total' => 0,
                ],
            ]);
    });
});
