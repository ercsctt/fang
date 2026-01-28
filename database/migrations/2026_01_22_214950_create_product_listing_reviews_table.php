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
        Schema::create('product_listing_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_listing_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('author')->nullable();
            $table->decimal('rating', 2, 1); // 0.0 to 5.0
            $table->string('title')->nullable();
            $table->text('body');
            $table->boolean('verified_purchase')->default(false);
            $table->date('review_date')->nullable();
            $table->integer('helpful_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['product_listing_id', 'external_id']);
            $table->index('rating');
            $table->index('review_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_listing_reviews');
    }
};
