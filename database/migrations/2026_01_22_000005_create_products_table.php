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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('brand')->nullable();
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('subcategory')->nullable();
            $table->integer('weight_grams')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('primary_image')->nullable();
            $table->integer('average_price_pence')->nullable();
            $table->integer('lowest_price_pence')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Only create fulltext index on databases that support it (not SQLite)
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->fullText(['name', 'brand']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
