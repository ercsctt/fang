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
        Schema::table('product_listing_matches', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('verified_at');
            $table->text('rejection_reason')->nullable()->after('status');

            $table->index('status');
            $table->index(['status', 'confidence_score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_listing_matches', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['status', 'confidence_score']);
            $table->dropColumn(['status', 'rejection_reason']);
        });
    }
};
