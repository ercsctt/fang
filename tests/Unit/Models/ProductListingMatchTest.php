<?php

declare(strict_types=1);

use App\Enums\MatchType;
use App\Models\Product;
use App\Models\ProductListing;
use App\Models\ProductListingMatch;
use App\Models\User;

describe('scopes', function () {
    test('verified scope returns only verified matches', function () {
        ProductListingMatch::factory()->verified()->count(2)->create();
        ProductListingMatch::factory()->count(3)->create(['verified_at' => null, 'verified_by' => null]);

        $verifiedMatches = ProductListingMatch::query()->verified()->get();

        expect($verifiedMatches)->toHaveCount(2)
            ->and($verifiedMatches->every(fn ($match) => $match->verified_at !== null))->toBeTrue();
    });

    test('unverified scope returns only unverified matches', function () {
        ProductListingMatch::factory()->verified()->count(2)->create();
        ProductListingMatch::factory()->count(3)->create(['verified_at' => null, 'verified_by' => null]);

        $unverifiedMatches = ProductListingMatch::query()->unverified()->get();

        expect($unverifiedMatches)->toHaveCount(3)
            ->and($unverifiedMatches->every(fn ($match) => $match->verified_at === null))->toBeTrue();
    });

    test('highConfidence scope returns matches above threshold', function () {
        ProductListingMatch::factory()->count(2)->create(['confidence_score' => 95.0]);
        ProductListingMatch::factory()->count(3)->create(['confidence_score' => 85.0]);
        ProductListingMatch::factory()->count(1)->create(['confidence_score' => 75.0]);

        $highConfidenceMatches = ProductListingMatch::query()->highConfidence(90.0)->get();

        expect($highConfidenceMatches)->toHaveCount(2)
            ->and($highConfidenceMatches->every(fn ($match) => $match->confidence_score >= 90.0))->toBeTrue();
    });

    test('highConfidence scope uses default threshold of 90', function () {
        ProductListingMatch::factory()->count(2)->create(['confidence_score' => 92.0]);
        ProductListingMatch::factory()->count(1)->create(['confidence_score' => 89.0]);

        expect(ProductListingMatch::query()->highConfidence()->count())->toBe(2);
    });

    test('byType scope filters by match type', function () {
        ProductListingMatch::factory()->exact()->count(2)->create();
        ProductListingMatch::factory()->fuzzy()->count(3)->create();
        ProductListingMatch::factory()->barcode()->count(1)->create();

        $exactMatches = ProductListingMatch::query()->byType(MatchType::Exact)->get();

        expect($exactMatches)->toHaveCount(2)
            ->and($exactMatches->every(fn ($match) => $match->match_type === MatchType::Exact))->toBeTrue();
    });
});

describe('verify method', function () {
    test('sets verified_by to user id', function () {
        $user = User::factory()->create();
        $match = ProductListingMatch::factory()->create(['verified_at' => null, 'verified_by' => null]);

        $match->verify($user);

        expect($match->verified_by)->toBe($user->id);
    });

    test('sets verified_at to current time', function () {
        $user = User::factory()->create();
        $match = ProductListingMatch::factory()->create(['verified_at' => null, 'verified_by' => null]);

        $this->travelTo(now());
        $match->verify($user);

        expect($match->verified_at->toDateTimeString())->toBe(now()->toDateTimeString());
    });

    test('persists the verification to database', function () {
        $user = User::factory()->create();
        $match = ProductListingMatch::factory()->create(['verified_at' => null, 'verified_by' => null]);

        $match->verify($user);

        $match->refresh();
        expect($match->verified_by)->toBe($user->id)
            ->and($match->verified_at)->not->toBeNull();
    });
});

describe('isVerified helper', function () {
    test('returns true when verified_at is set', function () {
        $match = ProductListingMatch::factory()->verified()->make();

        expect($match->isVerified())->toBeTrue();
    });

    test('returns false when verified_at is null', function () {
        $match = ProductListingMatch::factory()->make(['verified_at' => null]);

        expect($match->isVerified())->toBeFalse();
    });
});

describe('MatchType enum integration', function () {
    test('match_type is cast to MatchType enum', function () {
        $match = ProductListingMatch::factory()->exact()->create();

        expect($match->match_type)->toBeInstanceOf(MatchType::class)
            ->and($match->match_type)->toBe(MatchType::Exact);
    });

    it('stores all match types correctly', function (MatchType $type) {
        $match = ProductListingMatch::factory()->create(['match_type' => $type]);

        $match->refresh();
        expect($match->match_type)->toBe($type);
    })->with([
        'exact' => MatchType::Exact,
        'fuzzy' => MatchType::Fuzzy,
        'barcode' => MatchType::Barcode,
        'manual' => MatchType::Manual,
    ]);

    test('MatchType enum has correct labels', function () {
        expect(MatchType::Exact->label())->toBe('Exact Match')
            ->and(MatchType::Fuzzy->label())->toBe('Fuzzy Match')
            ->and(MatchType::Barcode->label())->toBe('Barcode Match')
            ->and(MatchType::Manual->label())->toBe('Manual Match');
    });
});

describe('relationships', function () {
    test('belongs to product', function () {
        $product = Product::factory()->create();
        $match = ProductListingMatch::factory()->for($product)->create();

        expect($match->product)->toBeInstanceOf(Product::class)
            ->and($match->product->id)->toBe($product->id);
    });

    test('belongs to product listing', function () {
        $listing = ProductListing::factory()->create();
        $match = ProductListingMatch::factory()->for($listing, 'productListing')->create();

        expect($match->productListing)->toBeInstanceOf(ProductListing::class)
            ->and($match->productListing->id)->toBe($listing->id);
    });

    test('belongs to verifier user', function () {
        $user = User::factory()->create();
        $match = ProductListingMatch::factory()->verified($user)->create();

        expect($match->verifier)->toBeInstanceOf(User::class)
            ->and($match->verifier->id)->toBe($user->id);
    });

    test('verifier returns null when not verified', function () {
        $match = ProductListingMatch::factory()->create(['verified_by' => null, 'verified_at' => null]);

        expect($match->verifier)->toBeNull();
    });
});

describe('casts', function () {
    test('confidence_score is cast to float', function () {
        $match = ProductListingMatch::factory()->create(['confidence_score' => 95]);

        expect($match->confidence_score)->toBeFloat()
            ->and($match->confidence_score)->toBe(95.0);
    });

    test('matched_at is cast to datetime', function () {
        $match = ProductListingMatch::factory()->create();

        expect($match->matched_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });

    test('verified_at is cast to datetime', function () {
        $match = ProductListingMatch::factory()->verified()->create();

        expect($match->verified_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });

    test('status is cast to VerificationStatus enum', function () {
        $match = ProductListingMatch::factory()->pending()->create();

        expect($match->status)->toBeInstanceOf(\App\Enums\VerificationStatus::class)
            ->and($match->status)->toBe(\App\Enums\VerificationStatus::Pending);
    });
});

describe('status scopes', function () {
    test('pending scope returns only pending matches', function () {
        ProductListingMatch::factory()->pending()->count(3)->create();
        ProductListingMatch::factory()->approved()->count(2)->create();
        ProductListingMatch::factory()->rejected()->count(1)->create();

        $pendingMatches = ProductListingMatch::query()->pending()->get();

        expect($pendingMatches)->toHaveCount(3)
            ->and($pendingMatches->every(fn ($match) => $match->isPending()))->toBeTrue();
    });

    test('approved scope returns only approved matches', function () {
        ProductListingMatch::factory()->pending()->count(3)->create();
        ProductListingMatch::factory()->approved()->count(2)->create();
        ProductListingMatch::factory()->rejected()->count(1)->create();

        $approvedMatches = ProductListingMatch::query()->approved()->get();

        expect($approvedMatches)->toHaveCount(2)
            ->and($approvedMatches->every(fn ($match) => $match->isApproved()))->toBeTrue();
    });

    test('rejected scope returns only rejected matches', function () {
        ProductListingMatch::factory()->pending()->count(3)->create();
        ProductListingMatch::factory()->approved()->count(2)->create();
        ProductListingMatch::factory()->rejected()->count(1)->create();

        $rejectedMatches = ProductListingMatch::query()->rejected()->get();

        expect($rejectedMatches)->toHaveCount(1)
            ->and($rejectedMatches->every(fn ($match) => $match->isRejected()))->toBeTrue();
    });

    test('lowConfidence scope returns matches below threshold', function () {
        ProductListingMatch::factory()->count(2)->create(['confidence_score' => 95.0]);
        ProductListingMatch::factory()->count(3)->create(['confidence_score' => 65.0]);

        $lowConfidenceMatches = ProductListingMatch::query()->lowConfidence(70.0)->get();

        expect($lowConfidenceMatches)->toHaveCount(3)
            ->and($lowConfidenceMatches->every(fn ($match) => $match->confidence_score < 70.0))->toBeTrue();
    });
});

describe('status methods', function () {
    test('approve sets status to approved', function () {
        $user = User::factory()->create();
        $match = ProductListingMatch::factory()->pending()->create();

        $match->approve($user);

        expect($match->status)->toBe(\App\Enums\VerificationStatus::Approved)
            ->and($match->verified_by)->toBe($user->id)
            ->and($match->verified_at)->not->toBeNull()
            ->and($match->rejection_reason)->toBeNull();
    });

    test('reject sets status to rejected with reason', function () {
        $user = User::factory()->create();
        $match = ProductListingMatch::factory()->pending()->create();

        $match->reject($user, 'Product mismatch');

        expect($match->status)->toBe(\App\Enums\VerificationStatus::Rejected)
            ->and($match->verified_by)->toBe($user->id)
            ->and($match->verified_at)->not->toBeNull()
            ->and($match->rejection_reason)->toBe('Product mismatch');
    });

    test('reject sets status to rejected without reason', function () {
        $user = User::factory()->create();
        $match = ProductListingMatch::factory()->pending()->create();

        $match->reject($user);

        expect($match->status)->toBe(\App\Enums\VerificationStatus::Rejected)
            ->and($match->rejection_reason)->toBeNull();
    });

    test('isPending returns true when status is pending', function () {
        $match = ProductListingMatch::factory()->pending()->make();

        expect($match->isPending())->toBeTrue();
    });

    test('isApproved returns true when status is approved', function () {
        $match = ProductListingMatch::factory()->approved()->make();

        expect($match->isApproved())->toBeTrue();
    });

    test('isRejected returns true when status is rejected', function () {
        $match = ProductListingMatch::factory()->rejected()->make();

        expect($match->isRejected())->toBeTrue();
    });
});
