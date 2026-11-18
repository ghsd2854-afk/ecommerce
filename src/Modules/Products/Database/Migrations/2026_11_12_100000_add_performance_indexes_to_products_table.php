<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requirement 3 (layer 3): indexes for the slow product-list queries found with EXPLAIN
 * (see: php artisan perf:explain).
 *
 *   GET /products?sort=price_asc                 ORDER BY price, id LIMIT 12
 *       before: type=ALL, "Using filesort" (reads + sorts ALL products for 12 rows)
 *       after : index (price) -> reads the first 12 entries of the index, no sort
 *   GET /products?category=X&sort=price_asc      WHERE category=? ORDER BY price, id
 *       before: index (category) finds the rows but still "Using filesort"
 *       after : index (category, price) -> already in the right order, no sort
 *   GET /products?sort=name                      ORDER BY name, id
 *       after : index (name)
 *
 * In InnoDB every secondary index also stores the primary key (id) at the end, so
 * (price) works as (price, id) and (category, price) as (category, price, id).
 *
 * The old single index (category) is now redundant: (category, price) starts with
 * category, so it serves the same lookups. It is removed, because every extra index
 * makes INSERT / UPDATE / DELETE slower and uses disk and memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['category', 'price'], 'products_category_price_index');
            $table->index('price', 'products_price_index');
            $table->index('name', 'products_name_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_category_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index('category', 'products_category_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_category_price_index');
            $table->dropIndex('products_price_index');
            $table->dropIndex('products_name_index');
        });
    }
};
