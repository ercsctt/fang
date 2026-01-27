<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only run on PostgreSQL
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Add tsvector column for full-text search
        DB::statement('ALTER TABLE products ADD COLUMN search_vector tsvector');

        // Create GIN index for efficient full-text search
        DB::statement('CREATE INDEX products_search_vector_idx ON products USING GIN (search_vector)');

        // Create function to update search vector
        DB::statement("
            CREATE OR REPLACE FUNCTION products_search_vector_update() RETURNS trigger AS \$\$
            BEGIN
                NEW.search_vector :=
                    setweight(to_tsvector('english', COALESCE(NEW.name, '')), 'A') ||
                    setweight(to_tsvector('english', COALESCE(NEW.brand, '')), 'A') ||
                    setweight(to_tsvector('english', COALESCE(NEW.description, '')), 'B') ||
                    setweight(to_tsvector('english', COALESCE(NEW.category, '')), 'C') ||
                    setweight(to_tsvector('english', COALESCE(NEW.subcategory, '')), 'C');
                RETURN NEW;
            END
            \$\$ LANGUAGE plpgsql;
        ");

        // Create trigger to auto-update search vector
        DB::statement('
            CREATE TRIGGER products_search_vector_trigger
            BEFORE INSERT OR UPDATE ON products
            FOR EACH ROW EXECUTE FUNCTION products_search_vector_update();
        ');

        // Backfill existing products
        DB::statement("
            UPDATE products SET search_vector =
                setweight(to_tsvector('english', COALESCE(name, '')), 'A') ||
                setweight(to_tsvector('english', COALESCE(brand, '')), 'A') ||
                setweight(to_tsvector('english', COALESCE(description, '')), 'B') ||
                setweight(to_tsvector('english', COALESCE(category, '')), 'C') ||
                setweight(to_tsvector('english', COALESCE(subcategory, '')), 'C');
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only run on PostgreSQL
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS products_search_vector_trigger ON products');
        DB::statement('DROP FUNCTION IF EXISTS products_search_vector_update()');
        DB::statement('DROP INDEX IF EXISTS products_search_vector_idx');
        DB::statement('ALTER TABLE products DROP COLUMN IF EXISTS search_vector');
    }
};
