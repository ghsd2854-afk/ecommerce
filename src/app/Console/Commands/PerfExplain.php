<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Requirement 3: analyse the slow queries with EXPLAIN, before and after the indexes.
 *
 *   php artisan perf:explain             EXPLAIN + time of each query in the CURRENT state
 *   php artisan perf:explain --compare   drops the indexes, measures, creates them, measures,
 *                                        and prints BEFORE vs AFTER side by side
 *
 * How to read EXPLAIN:
 *   type   ALL = reads the whole table (bad on big tables) | range / ref = uses an index
 *          index = reads a whole index in order | const = one row by key
 *   key    the index MySQL chose (NULL = none)
 *   rows   how many rows MySQL expects to read
 *   Extra  "Using filesort" = sorts the rows itself (slow on many rows)
 *          "Using index"    = the index alone has all the columns (covering index)
 *          "Using temporary"= an internal temporary table (GROUP BY)
 *
 * These are the exact SQL statements Laravel sends for the endpoints (checked with toSql()).
 */
class PerfExplain extends Command
{
    protected $signature = 'perf:explain {--compare : measure without and with the indexes} {--runs=5 : runs per query (median)}';

    protected $description = 'Requirement 3: EXPLAIN + timing of the slow queries (before / after the indexes)';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->error('perf:explain needs MySQL (run it in Docker: docker compose exec app php artisan perf:explain).');

            return self::FAILURE;
        }

        // Everything on the PRIMARY (where the indexes are changed); see PerfSeed.
        DB::connection()->recordsHaveBeenModified();

        if (DB::table('orders')->count() < 10000) {
            $this->warn('Only '.DB::table('orders')->count().' orders: run "php artisan perf:seed" first, otherwise every query is fast.');
        }

        $queries = $this->queries();

        if (! $this->option('compare')) {
            $this->report($queries, $this->measure($queries), PerfMode::indexesExist() ? 'with indexes' : 'without indexes');

            return self::SUCCESS;
        }

        $hadIndexes = PerfMode::indexesExist();

        Artisan::call('perf:mode', ['--indexes' => 'off']);
        $before = $this->measure($queries);
        $writesBefore = $this->measureWrites();
        Artisan::call('perf:mode', ['--indexes' => 'on']);
        $after = $this->measure($queries);
        $writesAfter = $this->measureWrites();

        if (! $hadIndexes) {
            Artisan::call('perf:mode', ['--indexes' => 'off']);
        }

        $this->report($queries, $before, 'BEFORE (no indexes)', $after);

        $this->newLine();
        $this->line('<info>The other side: what indexes slow down (writes)</info>');
        $this->table([''.self::WRITES.' single-row INSERTs into products (rolled back)', 'time'], [
            ['without the new indexes', sprintf('%.0f ms', $writesBefore)],
            ['with the new indexes', sprintf('%.0f ms', $writesAfter)],
            ['', sprintf('%+.0f %%', ($writesAfter / max($writesBefore, 0.001) - 1) * 100)],
        ]);
        $this->line('Every INSERT / UPDATE / DELETE must also update each index (+ disk and memory for every index).');

        return self::SUCCESS;
    }

    private const WRITES = 3000;

    /** Time of WRITES single-row inserts (median of 3 runs), each run rolled back: nothing is kept. */
    private function measureWrites(): float
    {
        $db = DB::connection();
        $runs = [];
        for ($r = 0; $r < 3; $r++) {
            $db->beginTransaction();
            $start = hrtime(true);
            for ($i = 0; $i < self::WRITES; $i++) {
                $db->insert('insert into `products` (`name`, `price`, `category`, `created_at`, `updated_at`) values (?, ?, ?, now(), now())',
                    ['Write test '.mt_rand(), mt_rand(100, 200000) / 100, 'Category '.mt_rand(1, 12)]);
            }
            $runs[] = (hrtime(true) - $start) / 1e6;
            $db->rollBack();
        }
        sort($runs);

        return $runs[1];
    }

    /** @return array<string, array{endpoint: string, sql: string, bindings: array}> */
    private function queries(): array
    {
        $category = DB::table('products')->value('category') ?? 'Audio';
        $userId = DB::table('orders')->orderByDesc('id')->value('user_id') ?? 1;
        $since = now()->subDays(29)->startOfDay()->toDateTimeString();

        return [
            'Q1 products by price' => [
                'endpoint' => 'GET /products?sort=price_asc',
                'sql' => 'select * from `products` order by `price` asc, `id` asc limit 12 offset 0',
                'bindings' => [],
            ],
            'Q2 category by price' => [
                'endpoint' => "GET /products?category=$category&sort=price_asc",
                'sql' => 'select * from `products` where `category` = ? order by `price` asc, `id` asc limit 12 offset 0',
                'bindings' => [$category],
            ],
            'Q3 products by name' => [
                'endpoint' => 'GET /products?sort=name',
                'sql' => 'select * from `products` order by `name` asc, `id` asc limit 12 offset 0',
                'bindings' => [],
            ],
            'Q4 sales report' => [
                'endpoint' => 'GET /orders/stats?days=30 (admin)',
                'sql' => 'select DATE(created_at) as day, COUNT(*) as orders, SUM(total) as revenue from `orders` '
                    .'where `created_at` >= ? and `status` <> ? group by DATE(created_at) order by `day` asc',
                'bindings' => [$since, 'cancelled'],
            ],
            'Q5 my orders' => [
                'endpoint' => 'GET /orders (customer)',
                'sql' => 'select * from `orders` where `user_id` = ? order by `id` desc limit 10 offset 0',
                'bindings' => [$userId],
            ],
        ];
    }

    /** EXPLAIN + median time of each query, on the PRIMARY (the replica may still be applying the index change). */
    private function measure(array $queries): array
    {
        $db = DB::connection();
        $results = [];

        foreach ($queries as $name => $q) {
            $plan = (array) ($db->select('explain '.$q['sql'], $q['bindings'], false)[0] ?? []);

            $times = [];
            for ($i = 0; $i < max(1, (int) $this->option('runs')); $i++) {
                $start = hrtime(true);
                $db->select($q['sql'], $q['bindings'], false);
                $times[] = (hrtime(true) - $start) / 1e6;
            }
            sort($times);

            $results[$name] = [
                'type' => $plan['type'] ?? '?',
                'key' => $plan['key'] ?? 'NULL',
                'rows' => (int) ($plan['rows'] ?? 0),
                'extra' => $plan['Extra'] ?? '',
                'ms' => $times[intdiv(count($times), 2)],
            ];
        }

        return $results;
    }

    private function report(array $queries, array $first, string $firstLabel, ?array $second = null): void
    {
        foreach ($queries as $name => $q) {
            $this->newLine();
            $this->line("<info>$name</info>  <comment>{$q['endpoint']}</comment>");
            $this->line('  '.$this->inline($q['sql'], $q['bindings']));

            $rows = [[$firstLabel, ...$this->cells($first[$name])]];
            if ($second !== null) {
                $rows[] = ['AFTER (with indexes)', ...$this->cells($second[$name])];
                $speedup = $second[$name]['ms'] > 0 ? $first[$name]['ms'] / $second[$name]['ms'] : 0;
                $rows[] = ['', '', '', '', '', $first[$name]['key'] === $second[$name]['key']
                    ? 'same plan: already indexed' : sprintf('x%.1f faster', $speedup)];
            }
            $this->table(['', 'type', 'key', 'rows', 'Extra', 'time (median)'], $rows);
        }

        $this->newLine();
        $this->line('Indexes are not free: each one is updated on every INSERT / UPDATE / DELETE and uses disk + memory.');
        $this->line('That is why only these were added (for queries measured as slow), and the redundant (category) index was removed.');
    }

    private function cells(array $r): array
    {
        return [$r['type'], $r['key'] ?? 'NULL', number_format($r['rows']), $r['extra'], sprintf('%.2f ms', $r['ms'])];
    }

    private function inline(string $sql, array $bindings): string
    {
        foreach ($bindings as $b) {
            $sql = preg_replace('/\?/', "'".$b."'", $sql, 1);
        }

        return $sql;
    }
}
