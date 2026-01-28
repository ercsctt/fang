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
        Schema::table('product_listing_reviews', function (Blueprint $table) {
            $table->string('sentiment', 20)->nullable()->after('metadata');
            $table->decimal('sentiment_score', 4, 3)->nullable()->after('sentiment');
            $table->decimal('sentiment_confidence', 3, 2)->nullable()->after('sentiment_score');
            $table->json('sentiment_keywords')->nullable()->after('sentiment_confidence');
            $table->timestamp('sentiment_analyzed_at')->nullable()->after('sentiment_keywords');

            $table->index('sentiment');
            $table->index('sentiment_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_listing_reviews', function (Blueprint $table) {
            $table->dropIndex(['sentiment']);
            $table->dropIndex(['sentiment_score']);

            $table->dropColumn([
                'sentiment',
                'sentiment_score',
                'sentiment_confidence',
                'sentiment_keywords',
                'sentiment_analyzed_at',
            ]);
        });
    }
};
