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
        Schema::create('product_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('url');
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('price_pence')->nullable();
            $table->integer('original_price_pence')->nullable();
            $table->string('currency', 3)->default('GBP');
            $table->integer('weight_grams')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('brand')->nullable();
            $table->string('category')->nullable();
            $table->json('images')->nullable();
            $table->text('ingredients')->nullable();
            $table->json('nutritional_info')->nullable();
            $table->boolean('in_stock')->default(true);
            $table->integer('stock_quantity')->nullable();
            $table->timestamp('last_scraped_at')->nullable();
            $table->timestamps();

            $table->index('retailer_id');
            $table->index('external_id');
            $table->unique(['retailer_id', 'url']);
            $table->index('brand');
            $table->index('category');
            $table->index('in_stock');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_listings');
    }
};
