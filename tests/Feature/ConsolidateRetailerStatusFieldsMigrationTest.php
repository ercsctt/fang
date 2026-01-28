<?php

declare(strict_types=1);

use App\Enums\RetailerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('migration adds status column', function () {
    expect(Schema::hasColumn('retailers', 'status'))->toBeTrue();
});

test('migration removes is_active and health_status columns', function () {
    expect(Schema::hasColumn('retailers', 'is_active'))->toBeFalse();
    expect(Schema::hasColumn('retailers', 'health_status'))->toBeFalse();
});

test('migration keeps monitoring fields', function () {
    expect(Schema::hasColumn('retailers', 'last_failure_at'))->toBeTrue();
    expect(Schema::hasColumn('retailers', 'consecutive_failures'))->toBeTrue();
    expect(Schema::hasColumn('retailers', 'paused_until'))->toBeTrue();
});

test('migration converts is_active false to Disabled status', function () {
    // Manually rollback the consolidation migration to test data migration
    DB::statement('ALTER TABLE retailers ADD COLUMN is_active BOOLEAN DEFAULT TRUE');
    DB::statement("ALTER TABLE retailers ADD COLUMN health_status VARCHAR(255) DEFAULT 'healthy'");
    DB::statement('ALTER TABLE retailers DROP COLUMN status');

    // Insert test data
    $retailerId = DB::table('retailers')->insertGetId([
        'name' => 'Test Retailer',
        'slug' => 'test-retailer',
        'base_url' => 'https://example.com',
        'is_active' => false,
        'health_status' => 'healthy',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Run the migration manually
    $this->artisan('migrate:refresh', ['--path' => 'database/migrations/2026_01_28_155522_consolidate_retailer_status_fields.php']);

    // Check the result
    $this->assertDatabaseHas('retailers', [
        'id' => $retailerId,
        'status' => RetailerStatus::Disabled->value,
    ]);
})->skip('Manual migration testing - run separately');

test('status column uses correct default value', function () {
    $retailer = DB::table('retailers')->insertGetId([
        'name' => 'Default Test',
        'slug' => 'default-test',
        'base_url' => 'https://example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = DB::table('retailers')->find($retailer);
    expect($result->status)->toBe(RetailerStatus::Active->value);
});

test('can insert retailers with all RetailerStatus enum values', function () {
    $statuses = [
        RetailerStatus::Active,
        RetailerStatus::Paused,
        RetailerStatus::Disabled,
        RetailerStatus::Degraded,
        RetailerStatus::Failed,
    ];

    foreach ($statuses as $status) {
        $id = DB::table('retailers')->insertGetId([
            'name' => "Test {$status->value}",
            'slug' => "test-{$status->value}",
            'base_url' => 'https://example.com',
            'status' => $status->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $retailer = DB::table('retailers')->find($id);
        expect($retailer->status)->toBe($status->value);
    }
});

test('paused_until column still exists after migration', function () {
    $retailerId = DB::table('retailers')->insertGetId([
        'name' => 'Paused Retailer',
        'slug' => 'paused-retailer',
        'base_url' => 'https://example.com',
        'status' => RetailerStatus::Paused->value,
        'paused_until' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $retailer = DB::table('retailers')->find($retailerId);
    expect($retailer->paused_until)->not->toBeNull();
});

test('monitoring fields still exist after migration', function () {
    $retailerId = DB::table('retailers')->insertGetId([
        'name' => 'Failed Retailer',
        'slug' => 'failed-retailer',
        'base_url' => 'https://example.com',
        'status' => RetailerStatus::Failed->value,
        'last_failure_at' => now(),
        'consecutive_failures' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $retailer = DB::table('retailers')->find($retailerId);
    expect($retailer->last_failure_at)->not->toBeNull();
    expect($retailer->consecutive_failures)->toBe(5);
});
