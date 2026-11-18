<?php

namespace App\Console\Commands;

use App\Support\Performance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Orders\Database\Seeders\PerformanceOrdersSeeder;
use Modules\Products\Database\Seeders\PerformanceProductsSeeder;
use Modules\Users\Database\Seeders\PerformanceUsersSeeder;

/**
 * Removes the fake performance data again (perf-N@shop.test customers, their orders,
 * "Perf ..." products), so the shop shows only the normal demo data.
 * The normal accounts, products and orders are not touched.
 */
class PerfClean extends Command
{
    protected $signature = 'perf:clean {--force : do not ask}';

    protected $description = 'Requirement 3: delete the fake performance data created by perf:seed';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Delete all performance data (perf customers, their orders, Perf products)?', true)) {
            return self::SUCCESS;
        }

        // Maintenance command: read from the PRIMARY only. The read replica may still be
        // applying the previous big change (replication lag) and would return old counts / ids.
        DB::connection()->recordsHaveBeenModified();
        $users = app(PerformanceUsersSeeder::class);
        $ids = $users->ids();

        $this->line('orders:   '.number_format(app(PerformanceOrdersSeeder::class)->clean($ids)).' deleted');
        $this->line('products: '.number_format(app(PerformanceProductsSeeder::class)->clean()).' deleted');
        $this->line('users:    '.number_format($users->clean()).' deleted');

        // The cached catalog contained the deleted products.
        Performance::store()->forever('products:version', Str::random(12));
        Performance::store()->forget('orders:admin_total:all');

        return self::SUCCESS;
    }
}
