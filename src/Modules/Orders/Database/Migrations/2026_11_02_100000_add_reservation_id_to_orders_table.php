<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Id of the stock reservation made in the inventory service for this order.
            $table->string('reservation_id', 64)->nullable()->unique()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['reservation_id']);
            $table->dropColumn('reservation_id');
        });
    }
};
