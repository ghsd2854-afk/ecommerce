<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requirement 3 (layer 3): index for the admin sales report (GET /orders/stats),
 * found slow with EXPLAIN on hundreds of thousands of orders (php artisan perf:explain):
 *
 *   SELECT DATE(created_at), COUNT(*), SUM(total) FROM orders
 *   WHERE created_at >= ? AND status <> 'cancelled' GROUP BY ...
 *
 *   before: type=ALL -> reads EVERY order of every year to keep only the last 30 days
 *   after : type=range on (created_at, status, total) -> reads only the last 30 days,
 *           and "Using index": all the needed columns are IN the index (covering index),
 *           so the table rows are not read at all.
 *
 * Already fast without new indexes (checked with EXPLAIN, nothing added):
 *   - a customer's orders   WHERE user_id = ? ORDER BY id DESC   -> index (user_id)
 *   - items of the orders   WHERE order_id IN (...)              -> index (order_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['created_at', 'status', 'total'], 'orders_created_at_status_total_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_created_at_status_total_index');
        });
    }
};
