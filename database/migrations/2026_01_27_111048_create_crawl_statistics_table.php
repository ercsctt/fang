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
        Schema::create('crawl_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('crawls_started')->default(0);
            $table->unsignedInteger('crawls_completed')->default(0);
            $table->unsignedInteger('crawls_failed')->default(0);
            $table->unsignedInteger('listings_discovered')->default(0);
            $table->unsignedInteger('details_extracted')->default(0);
            $table->unsignedInteger('average_duration_ms')->nullable();
            $table->timestamps();

            $table->unique(['retailer_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crawl_statistics');
    }
};
