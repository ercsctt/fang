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
        Schema::create('product_listing_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_listing_id')->constrained()->cascadeOnDelete();
            $table->decimal('confidence_score', 5, 2); // 0.00 to 100.00
            $table->string('match_type'); // Use enum values
            $table->timestamp('matched_at');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique('product_listing_id'); // One canonical product per listing
            $table->index(['product_id', 'confidence_score']);
            $table->index('match_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_listing_matches');
    }
};
