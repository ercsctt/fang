<?php

use App\Enums\RetailerStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Add new status column
        Schema::table('retailers', function (Blueprint $table) {
            $table->string('status')->default(RetailerStatus::Active->value)->after('crawler_class');
        });

        // Step 2: Migrate existing data
        // Priority order (first match wins):
        // 1. is_active=false → Disabled
        // 2. health_status='unhealthy' → Failed
        // 3. paused_until in future → Paused
        // 4. health_status='degraded' → Degraded (bonus: preserve degraded state)
        // 5. Otherwise → Active

        // Handle is_active=false → Disabled
        DB::table('retailers')
            ->where('is_active', false)
            ->update(['status' => RetailerStatus::Disabled->value]);

        // Handle health_status='unhealthy' → Failed (only if not already Disabled)
        DB::table('retailers')
            ->where('health_status', 'unhealthy')
            ->where('is_active', true)
            ->update(['status' => RetailerStatus::Failed->value]);

        // Handle paused_until in future → Paused (only if not already Disabled or Failed)
        DB::table('retailers')
            ->where('paused_until', '>', now())
            ->where('is_active', true)
            ->whereNotIn('status', [RetailerStatus::Disabled->value, RetailerStatus::Failed->value])
            ->update(['status' => RetailerStatus::Paused->value]);

        // Handle health_status='degraded' → Degraded (only if not already set to something else)
        DB::table('retailers')
            ->where('health_status', 'degraded')
            ->where('status', RetailerStatus::Active->value)
            ->update(['status' => RetailerStatus::Degraded->value]);

        // Step 3: Remove old columns
        Schema::table('retailers', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'health_status']);
        });

        // Note: We keep last_failure_at, consecutive_failures, and paused_until
        // - last_failure_at and consecutive_failures are used for health monitoring
        // - paused_until is used with Paused status for auto-resume scheduling
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Step 1: Restore old columns
        Schema::table('retailers', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('crawler_class');
            $table->string('health_status')->default('healthy')->after('is_active');
        });

        // Step 2: Migrate data back
        // Active → is_active=true, health_status='healthy'
        DB::table('retailers')
            ->where('status', RetailerStatus::Active->value)
            ->update([
                'is_active' => true,
                'health_status' => 'healthy',
            ]);

        // Paused → is_active=true, health_status='healthy' (pause is tracked via paused_until)
        DB::table('retailers')
            ->where('status', RetailerStatus::Paused->value)
            ->update([
                'is_active' => true,
                'health_status' => 'healthy',
            ]);

        // Disabled → is_active=false, health_status='healthy'
        DB::table('retailers')
            ->where('status', RetailerStatus::Disabled->value)
            ->update([
                'is_active' => false,
                'health_status' => 'healthy',
            ]);

        // Degraded → is_active=true, health_status='degraded'
        DB::table('retailers')
            ->where('status', RetailerStatus::Degraded->value)
            ->update([
                'is_active' => true,
                'health_status' => 'degraded',
            ]);

        // Failed → is_active=true, health_status='unhealthy'
        DB::table('retailers')
            ->where('status', RetailerStatus::Failed->value)
            ->update([
                'is_active' => true,
                'health_status' => 'unhealthy',
            ]);

        // Step 3: Remove status column
        Schema::table('retailers', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
