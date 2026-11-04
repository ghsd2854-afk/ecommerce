<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module boundaries: the Orders tables must not be tied to the tables of other
     * modules (users, products) by database foreign keys. The ids stay as plain,
     * indexed columns; the rules are enforced through each module's public API.
     * (order_items.order_id -> orders stays: both tables belong to Orders.)
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products');
        });
    }
};
