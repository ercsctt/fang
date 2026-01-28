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
        Schema::create('image_caches', function (Blueprint $table) {
            $table->id();
            $table->string('original_url');
            $table->string('cached_path');
            $table->string('disk')->default('public');
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('file_size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->unsignedInteger('fetch_count')->default(0);
            $table->timestamps();

            $table->unique('original_url');
            $table->index('cached_path');
            $table->index('last_fetched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('image_caches');
    }
};
