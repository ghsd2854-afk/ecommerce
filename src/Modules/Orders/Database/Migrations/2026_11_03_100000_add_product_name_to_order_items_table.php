<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot of the product name at order time. Together with unit_price this means
     * an order never has to read the Products module's table to be displayed.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('product_name')->nullable()->after('product_id');
        });

        // One-time backfill for orders created before this change.
        if (Schema::hasTable('products')) {
            $names = DB::table('products')->pluck('name', 'id');
            DB::table('order_items')->whereNull('product_name')->eachById(function ($item) use ($names) {
                DB::table('order_items')->where('id', $item->id)
                    ->update(['product_name' => $names[$item->product_id] ?? 'Product #'.$item->product_id]);
            });
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('product_name');
        });
    }
};
