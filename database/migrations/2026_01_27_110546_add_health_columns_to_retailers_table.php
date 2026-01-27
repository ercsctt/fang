<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('retailers', function (Blueprint $table) {
            $table->string('health_status')->default('healthy')->after('is_active');
            $table->timestamp('last_failure_at')->nullable()->after('health_status');
            $table->unsignedInteger('consecutive_failures')->default(0)->after('last_failure_at');
            $table->timestamp('paused_until')->nullable()->after('consecutive_failures');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('retailers', function (Blueprint $table) {
            $table->dropColumn([
                'health_status',
                'last_failure_at',
                'consecutive_failures',
                'paused_until',
            ]);
        });
    }
};
