<?php

declare(strict_types=1);

use App\Enums\RetailerStatus;
use App\Models\Retailer;

describe('resume expired paused retailers command', function () {
    test('resumes retailers with expired pause', function () {
        $retailer = Retailer::factory()->create([
            'name' => 'Paused Store',
            'status' => RetailerStatus::Paused,
            'paused_until' => now()->subMinute(),
        ]);

        $this->artisan('retailers:resume-expired')
            ->expectsOutputToContain('Found 1 expired paused retailer(s) to resume')
            ->expectsOutputToContain('✓ Resumed: Paused Store')
            ->expectsOutputToContain('Resumed: 1 | Failed: 0')
            ->assertExitCode(0);

        $retailer->refresh();

        expect($retailer->status)->toBe(RetailerStatus::Active)
            ->and($retailer->paused_until)->toBeNull();
    });

    test('does not resume retailers whose pause has not expired', function () {
        $retailer = Retailer::factory()->create([
            'name' => 'Still Paused',
            'status' => RetailerStatus::Paused,
            'paused_until' => now()->addHour(),
        ]);

        $this->artisan('retailers:resume-expired')
            ->expectsOutputToContain('No expired paused retailers found')
            ->assertExitCode(0);

        $retailer->refresh();

        expect($retailer->status)->toBe(RetailerStatus::Paused)
            ->and($retailer->paused_until)->not->toBeNull();
    });

    test('does not affect non-paused retailers', function () {
        $active = Retailer::factory()->create([
            'status' => RetailerStatus::Active,
            'paused_until' => null,
        ]);

        $disabled = Retailer::factory()->create([
            'status' => RetailerStatus::Disabled,
            'paused_until' => null,
        ]);

        $this->artisan('retailers:resume-expired')
            ->expectsOutputToContain('No expired paused retailers found')
            ->assertExitCode(0);

        $active->refresh();
        $disabled->refresh();

        expect($active->status)->toBe(RetailerStatus::Active)
            ->and($disabled->status)->toBe(RetailerStatus::Disabled);
    });

    test('resumes multiple retailers', function () {
        Retailer::factory()->create([
            'name' => 'Store 1',
            'status' => RetailerStatus::Paused,
            'paused_until' => now()->subMinute(),
        ]);

        Retailer::factory()->create([
            'name' => 'Store 2',
            'status' => RetailerStatus::Paused,
            'paused_until' => now()->subHour(),
        ]);

        $this->artisan('retailers:resume-expired')
            ->expectsOutputToContain('Found 2 expired paused retailer(s) to resume')
            ->expectsOutputToContain('✓ Resumed: Store 1')
            ->expectsOutputToContain('✓ Resumed: Store 2')
            ->expectsOutputToContain('Resumed: 2 | Failed: 0')
            ->assertExitCode(0);
    });

    test('scheduled resume command is registered', function () {
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();

        $resumeEvent = collect($events)->first(function ($event) {
            return str_contains($event->command ?? '', 'retailers:resume-expired');
        });

        expect($resumeEvent)->not->toBeNull();
        expect($resumeEvent->timezone)->toBe('Europe/London');
        expect($resumeEvent->onOneServer)->toBeTrue();
    });
});
