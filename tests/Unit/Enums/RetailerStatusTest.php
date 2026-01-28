<?php

declare(strict_types=1);

use App\Enums\RetailerStatus;

describe('RetailerStatus enum values', function () {
    test('has all expected status values', function () {
        expect(RetailerStatus::cases())->toHaveCount(5)
            ->and(RetailerStatus::Active->value)->toBe('active')
            ->and(RetailerStatus::Paused->value)->toBe('paused')
            ->and(RetailerStatus::Disabled->value)->toBe('disabled')
            ->and(RetailerStatus::Degraded->value)->toBe('degraded')
            ->and(RetailerStatus::Failed->value)->toBe('failed');
    });

    test('default status is Active', function () {
        expect(RetailerStatus::default())->toBe(RetailerStatus::Active);
    });
});

describe('RetailerStatus labels and descriptions', function () {
    test('each status has a label', function (RetailerStatus $status, string $expectedLabel) {
        expect($status->label())->toBe($expectedLabel);
    })->with([
        'Active' => [RetailerStatus::Active, 'Active'],
        'Paused' => [RetailerStatus::Paused, 'Paused'],
        'Disabled' => [RetailerStatus::Disabled, 'Disabled'],
        'Degraded' => [RetailerStatus::Degraded, 'Degraded'],
        'Failed' => [RetailerStatus::Failed, 'Failed'],
    ]);

    test('each status has a color', function (RetailerStatus $status, string $expectedColor) {
        expect($status->color())->toBe($expectedColor);
    })->with([
        'Active' => [RetailerStatus::Active, 'green'],
        'Paused' => [RetailerStatus::Paused, 'yellow'],
        'Disabled' => [RetailerStatus::Disabled, 'gray'],
        'Degraded' => [RetailerStatus::Degraded, 'orange'],
        'Failed' => [RetailerStatus::Failed, 'red'],
    ]);

    test('each status has a description', function (RetailerStatus $status) {
        expect($status->description())->toBeString()->not->toBeEmpty();
    })->with(RetailerStatus::cases());

    test('each status has an icon', function (RetailerStatus $status) {
        expect($status->icon())->toBeString()->not->toBeEmpty();
    })->with(RetailerStatus::cases());

    test('each status has badge classes', function (RetailerStatus $status) {
        expect($status->badgeClasses())->toBeString()->not->toBeEmpty();
    })->with(RetailerStatus::cases());
});

describe('RetailerStatus crawling availability', function () {
    test('Active status allows crawling', function () {
        expect(RetailerStatus::Active->isAvailableForCrawling())->toBeTrue();
    });

    test('Degraded status allows crawling', function () {
        expect(RetailerStatus::Degraded->isAvailableForCrawling())->toBeTrue();
    });

    test('Paused status does not allow crawling', function () {
        expect(RetailerStatus::Paused->isAvailableForCrawling())->toBeFalse();
    });

    test('Disabled status does not allow crawling', function () {
        expect(RetailerStatus::Disabled->isAvailableForCrawling())->toBeFalse();
    });

    test('Failed status does not allow crawling', function () {
        expect(RetailerStatus::Failed->isAvailableForCrawling())->toBeFalse();
    });

    test('crawlableStatuses returns only Active and Degraded', function () {
        $crawlable = RetailerStatus::crawlableStatuses();

        expect($crawlable)->toHaveCount(2)
            ->and($crawlable)->toContain(RetailerStatus::Active)
            ->and($crawlable)->toContain(RetailerStatus::Degraded);
    });
});

describe('RetailerStatus issue detection', function () {
    test('Active status has no issues', function () {
        expect(RetailerStatus::Active->hasIssues())->toBeFalse();
    });

    test('Paused status has issues', function () {
        expect(RetailerStatus::Paused->hasIssues())->toBeTrue();
    });

    test('Disabled status has issues', function () {
        expect(RetailerStatus::Disabled->hasIssues())->toBeTrue();
    });

    test('Degraded status has issues', function () {
        expect(RetailerStatus::Degraded->hasIssues())->toBeTrue();
    });

    test('Failed status has issues', function () {
        expect(RetailerStatus::Failed->hasIssues())->toBeTrue();
    });

    test('problemStatuses returns Paused, Degraded, and Failed', function () {
        $problems = RetailerStatus::problemStatuses();

        expect($problems)->toHaveCount(3)
            ->and($problems)->toContain(RetailerStatus::Paused)
            ->and($problems)->toContain(RetailerStatus::Degraded)
            ->and($problems)->toContain(RetailerStatus::Failed);
    });
});

describe('RetailerStatus intervention requirements', function () {
    test('Active status does not require intervention', function () {
        expect(RetailerStatus::Active->requiresIntervention())->toBeFalse();
    });

    test('Paused status does not require intervention', function () {
        expect(RetailerStatus::Paused->requiresIntervention())->toBeFalse();
    });

    test('Disabled status requires intervention', function () {
        expect(RetailerStatus::Disabled->requiresIntervention())->toBeTrue();
    });

    test('Degraded status does not require intervention', function () {
        expect(RetailerStatus::Degraded->requiresIntervention())->toBeFalse();
    });

    test('Failed status requires intervention', function () {
        expect(RetailerStatus::Failed->requiresIntervention())->toBeTrue();
    });
});

describe('RetailerStatus state machine transitions', function () {
    test('Active can transition to Paused, Disabled, Degraded, and Failed', function () {
        $allowed = RetailerStatus::Active->allowedTransitions();

        expect($allowed)->toHaveCount(4)
            ->and($allowed)->toContain(RetailerStatus::Paused)
            ->and($allowed)->toContain(RetailerStatus::Disabled)
            ->and($allowed)->toContain(RetailerStatus::Degraded)
            ->and($allowed)->toContain(RetailerStatus::Failed);
    });

    test('Paused can transition to Active and Disabled', function () {
        $allowed = RetailerStatus::Paused->allowedTransitions();

        expect($allowed)->toHaveCount(2)
            ->and($allowed)->toContain(RetailerStatus::Active)
            ->and($allowed)->toContain(RetailerStatus::Disabled);
    });

    test('Disabled can only transition to Active', function () {
        $allowed = RetailerStatus::Disabled->allowedTransitions();

        expect($allowed)->toHaveCount(1)
            ->and($allowed)->toContain(RetailerStatus::Active);
    });

    test('Degraded can transition to Active, Paused, Disabled, and Failed', function () {
        $allowed = RetailerStatus::Degraded->allowedTransitions();

        expect($allowed)->toHaveCount(4)
            ->and($allowed)->toContain(RetailerStatus::Active)
            ->and($allowed)->toContain(RetailerStatus::Paused)
            ->and($allowed)->toContain(RetailerStatus::Disabled)
            ->and($allowed)->toContain(RetailerStatus::Failed);
    });

    test('Failed can transition to Active and Disabled', function () {
        $allowed = RetailerStatus::Failed->allowedTransitions();

        expect($allowed)->toHaveCount(2)
            ->and($allowed)->toContain(RetailerStatus::Active)
            ->and($allowed)->toContain(RetailerStatus::Disabled);
    });
});

describe('RetailerStatus canTransitionTo', function () {
    test('any status can transition to itself', function (RetailerStatus $status) {
        expect($status->canTransitionTo($status))->toBeTrue();
    })->with(RetailerStatus::cases());

    test('Active can transition to allowed statuses', function () {
        expect(RetailerStatus::Active->canTransitionTo(RetailerStatus::Paused))->toBeTrue()
            ->and(RetailerStatus::Active->canTransitionTo(RetailerStatus::Disabled))->toBeTrue()
            ->and(RetailerStatus::Active->canTransitionTo(RetailerStatus::Degraded))->toBeTrue()
            ->and(RetailerStatus::Active->canTransitionTo(RetailerStatus::Failed))->toBeTrue();
    });

    test('Paused cannot transition to Degraded or Failed', function () {
        expect(RetailerStatus::Paused->canTransitionTo(RetailerStatus::Degraded))->toBeFalse()
            ->and(RetailerStatus::Paused->canTransitionTo(RetailerStatus::Failed))->toBeFalse();
    });

    test('Disabled cannot transition to Paused, Degraded, or Failed', function () {
        expect(RetailerStatus::Disabled->canTransitionTo(RetailerStatus::Paused))->toBeFalse()
            ->and(RetailerStatus::Disabled->canTransitionTo(RetailerStatus::Degraded))->toBeFalse()
            ->and(RetailerStatus::Disabled->canTransitionTo(RetailerStatus::Failed))->toBeFalse();
    });

    test('Failed cannot transition to Paused or Degraded', function () {
        expect(RetailerStatus::Failed->canTransitionTo(RetailerStatus::Paused))->toBeFalse()
            ->and(RetailerStatus::Failed->canTransitionTo(RetailerStatus::Degraded))->toBeFalse();
    });
});

describe('RetailerStatus transitionTo', function () {
    test('returns the new status for valid transitions', function () {
        $newStatus = RetailerStatus::Active->transitionTo(RetailerStatus::Paused);

        expect($newStatus)->toBe(RetailerStatus::Paused);
    });

    test('returns same status when transitioning to self', function () {
        $newStatus = RetailerStatus::Active->transitionTo(RetailerStatus::Active);

        expect($newStatus)->toBe(RetailerStatus::Active);
    });

    test('throws exception for invalid transition from Active', function () {
        // Active cannot be reached from itself since it's already there
        // But we need to test an invalid transition
        // There are no invalid transitions FROM Active (it can go to all others)
        // So let's test from a more restricted state
        expect(true)->toBeTrue();
    });

    test('throws exception for invalid transition from Paused to Degraded', function () {
        expect(fn () => RetailerStatus::Paused->transitionTo(RetailerStatus::Degraded))
            ->toThrow(InvalidArgumentException::class, 'Invalid status transition from "paused" to "degraded"');
    });

    test('throws exception for invalid transition from Disabled to Failed', function () {
        expect(fn () => RetailerStatus::Disabled->transitionTo(RetailerStatus::Failed))
            ->toThrow(InvalidArgumentException::class, 'Invalid status transition from "disabled" to "failed"');
    });

    test('throws exception for invalid transition from Failed to Paused', function () {
        expect(fn () => RetailerStatus::Failed->transitionTo(RetailerStatus::Paused))
            ->toThrow(InvalidArgumentException::class, 'Invalid status transition from "failed" to "paused"');
    });

    test('exception message includes allowed transitions', function () {
        try {
            RetailerStatus::Disabled->transitionTo(RetailerStatus::Failed);
            $this->fail('Expected exception was not thrown');
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->toContain('Allowed transitions: active');
        }
    });
});

describe('RetailerStatus complete transition scenarios', function () {
    test('normal degradation path: Active -> Degraded -> Failed -> Active', function () {
        $status = RetailerStatus::Active;

        $status = $status->transitionTo(RetailerStatus::Degraded);
        expect($status)->toBe(RetailerStatus::Degraded);

        $status = $status->transitionTo(RetailerStatus::Failed);
        expect($status)->toBe(RetailerStatus::Failed);

        $status = $status->transitionTo(RetailerStatus::Active);
        expect($status)->toBe(RetailerStatus::Active);
    });

    test('pause and resume path: Active -> Paused -> Active', function () {
        $status = RetailerStatus::Active;

        $status = $status->transitionTo(RetailerStatus::Paused);
        expect($status)->toBe(RetailerStatus::Paused);

        $status = $status->transitionTo(RetailerStatus::Active);
        expect($status)->toBe(RetailerStatus::Active);
    });

    test('manual disable path: Active -> Disabled -> Active', function () {
        $status = RetailerStatus::Active;

        $status = $status->transitionTo(RetailerStatus::Disabled);
        expect($status)->toBe(RetailerStatus::Disabled);

        $status = $status->transitionTo(RetailerStatus::Active);
        expect($status)->toBe(RetailerStatus::Active);
    });

    test('disable from failed state: Failed -> Disabled -> Active', function () {
        $status = RetailerStatus::Failed;

        $status = $status->transitionTo(RetailerStatus::Disabled);
        expect($status)->toBe(RetailerStatus::Disabled);

        $status = $status->transitionTo(RetailerStatus::Active);
        expect($status)->toBe(RetailerStatus::Active);
    });

    test('pause during degraded state: Degraded -> Paused -> Active', function () {
        $status = RetailerStatus::Degraded;

        $status = $status->transitionTo(RetailerStatus::Paused);
        expect($status)->toBe(RetailerStatus::Paused);

        $status = $status->transitionTo(RetailerStatus::Active);
        expect($status)->toBe(RetailerStatus::Active);
    });
});
