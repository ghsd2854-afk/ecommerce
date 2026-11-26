docker run --rm -i -v "${PWD}:/scripts" grafana/k6 run /scripts/performance-test.js
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(
                ['price', 'id'],
                'products_price_id_idx'
            );

            $table->index(
                ['name', 'id'],
                'products_name_id_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_price_id_idx');
            $table->dropIndex('products_name_id_idx');
        });
    }
};
