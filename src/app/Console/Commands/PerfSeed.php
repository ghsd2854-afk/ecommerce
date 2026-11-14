<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Database\Seeders\PerformanceOrdersSeeder;
use Modules\Products\Database\Seeders\PerformanceProductsSeeder;
use Modules\Users\Database\Seeders\PerformanceUsersSeeder;

/**
 * Requirement 3, step 1: a database with a realistic amount of data.
 * With 8 products and 10 orders every query is fast, so nothing can be measured.
 *
 *   php artisan perf:seed                       (2,000 customers, 100,000 products, 300,000 orders)
 *   php artisan perf:seed --orders=500000       (more orders)
 *
 * Each module inserts its own data (module seeders). Runs once; running it again only
 * tops the data up to the requested numbers.
 */
class PerfSeed extends Command
{
    protected $signature = 'perf:seed
        {--users=2000 : fake customers (perf-N@shop.test / Password123)}
        {--products=100000 : fake products}
        {--orders=300000 : fake orders (each with 1-4 items)}';

    protected $description = 'Requirement 3: fill the database with large fake data (customers, products, hundreds of thousands of orders)';

    public function handle(): int
    {
        // Maintenance command: read from the PRIMARY only. The read replica may still be
        // applying the previous big change (replication lag) and would return old counts / ids.
        DB::connection()->recordsHaveBeenModified();
        mt_srand(2026); // same data on every machine
        DB::connection()->disableQueryLog();
        $started = microtime(true);

        $this->info('1/3 customers...');
        $users = app(PerformanceUsersSeeder::class)->seed((int) $this->option('users'));
        $this->line('    '.number_format(count($users)).' performance customers');

        $this->info('2/3 products...');
        $bar = $this->output->createProgressBar((int) $this->option('products'));
        $products = app(PerformanceProductsSeeder::class)->seed((int) $this->option('products'), fn (int $n) => $bar->advance($n));
        $bar->finish();
        $this->newLine();
        $this->line('    '.number_format(count($products['ids'])).' performance products');

        $missing = (int) $this->option('orders') - $this->existingPerfOrders($users);
        $this->info('3/3 orders...');
        if ($missing > 0) {
            $bar = $this->output->createProgressBar($missing);
            app(PerformanceOrdersSeeder::class)->seed($missing, $users, $products, fn (int $n) => $bar->advance($n));
            $bar->finish();
            $this->newLine();
        }

        $this->newLine();
        $this->table(['table', 'rows'], collect(['users', 'products', 'orders', 'order_items'])
            ->map(fn ($t) => [$t, number_format(DB::table($t)->count())])->all());
        $this->info(sprintf('Done in %.1f s. The read replica copies these rows in the background.', microtime(true) - $started));

        return self::SUCCESS;
    }

    /** Orders that already belong to performance customers. */
    private function existingPerfOrders(array $users): int
    {
        return $users === [] ? 0 : (int) DB::table('orders')
            ->whereBetween('user_id', [min($users), max($users)])->count();
    }
}
