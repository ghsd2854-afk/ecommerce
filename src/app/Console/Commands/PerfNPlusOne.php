<?php

namespace App\Console\Commands;

use App\Support\Performance;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Requirement 3: the N+1 problem, measured on the REAL endpoint GET /api/v1/orders.
 *
 * N+1 = 1 query for the list of N orders + 1 query PER order for its items.
 *   50 orders -> 51 queries, 500 orders -> 501 queries: it grows with the data.
 * Fix (eager loading, OrderController@index: ->with('items')):
 *   1 query for the orders + 1 query "WHERE order_id IN (...50 ids...)" = 2 queries, always.
 *
 *   php artisan perf:nplus1            (admin, 50 orders per page)
 */
class PerfNPlusOne extends Command
{
    protected $signature = 'perf:nplus1 {--per-page=50} {--runs=5}';

    protected $description = 'Requirement 3: count the SQL queries of GET /orders with and without eager loading (N+1)';

    public function handle(Kernel $kernel, Performance $performance): int
    {
        $adminId = DB::table('users')->where('role', 'admin')->value('id');
        if (! $adminId) {
            $this->error('No admin user. Run: php artisan db:seed');

            return self::FAILURE;
        }
        $token = auth('api')->tokenById($adminId);
        $uri = '/api/v1/orders?per_page='.(int) $this->option('per-page');

        $rows = [];
        $examples = [];
        foreach (['N+1 (lazy loading)' => false, 'fixed (eager loading)' => true] as $label => $eager) {
            $performance->override('eager_loading', $eager);
            $performance->override('etag', false);
            $performance->override('cache', false); // same queries every run (no cached total)

            $best = null;
            for ($i = 0; $i < max(1, (int) $this->option('runs')); $i++) {
                $queries = [];
                DB::listen(function (QueryExecuted $q) use (&$queries) {
                    $queries[] = $q->sql;
                });
                $start = hrtime(true);
                $response = $kernel->handle(Request::create($uri, 'GET', server: [
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                    'HTTP_ACCEPT' => 'application/json',
                ]));
                $ms = (hrtime(true) - $start) / 1e6;
                app('events')->forget(QueryExecuted::class);

                if ($response->getStatusCode() !== 200) {
                    $this->error("GET $uri -> ".$response->getStatusCode());

                    return self::FAILURE;
                }
                if ($best === null || $ms < $best['ms']) {
                    $best = ['ms' => $ms, 'queries' => $queries];
                }
            }

            $orders = count(json_decode($response->getContent(), true)['data'] ?? []);
            $itemQueries = count(array_filter($best['queries'], fn ($sql) => str_contains($sql, 'order_items')));
            $rows[] = [$label, $orders, count($best['queries']), $itemQueries, sprintf('%.1f ms', $best['ms'])];
            $examples[$label] = array_slice(array_values(array_filter($best['queries'], fn ($sql) => str_contains($sql, 'orders') || str_contains($sql, 'order_items'))), 0, 4);
        }

        $this->line("<info>GET $uri</info> as admin");
        $this->table(['', 'orders on the page', 'SQL queries (total)', 'queries on order_items', 'time (best of '.$this->option('runs').')'], $rows);

        foreach ($examples as $label => $sqls) {
            $this->line("<comment>$label</comment> - first queries:");
            foreach ($sqls as $sql) {
                $this->line('  '.\Illuminate\Support\Str::limit($sql, 150));
            }
            $this->line('  ...');
        }

        return self::SUCCESS;
    }
}
