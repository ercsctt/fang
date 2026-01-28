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
        Schema::create('product_listing_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_listing_id')->constrained()->cascadeOnDelete();
            $table->integer('price_pence');
            $table->integer('original_price_pence')->nullable();
            $table->string('currency', 3)->default('GBP');
            $table->timestamp('recorded_at');

            $table->index(['product_listing_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_listing_prices');
    }
};
