<?php

namespace App\Console\Commands;

use App\Support\Performance;
use App\Support\ReadYourWrites;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Requirement 3: switch the optimizations OFF / ON to measure BEFORE and AFTER
 * with exactly the same code and data ("measure -> improve one thing -> measure").
 *
 *   php artisan perf:mode                      show the current state
 *   php artisan perf:mode baseline             everything OFF (the app as it was after Requirement 2)
 *   php artisan perf:mode optimized            everything ON  (normal state)
 *   php artisan perf:mode --cache=off          change ONE thing
 *   php artisan perf:mode --load-test=on       lift the API rate limit while k6 runs (APP_ENV=local only)
 *
 * The indexes are removed / created with the module migrations themselves (down() / up()),
 * so the database is always in a state the migrations know.
 */
class PerfMode extends Command
{
    protected $signature = 'perf:mode
        {preset? : baseline | optimized}
        {--cache= : on|off  Redis cache (layer 2)}
        {--etag= : on|off  ETag / 304 (layer 1)}
        {--eager= : on|off  eager loading, no N+1 (layer 3)}
        {--indexes= : on|off  performance indexes (layer 3)}
        {--load-test= : on|off  lift the API rate limit for k6 (local only)}';

    protected $description = 'Requirement 3: switch the performance optimizations off/on to measure before and after';

    /** The migrations that add the Requirement-3 indexes (one per module). */
    public const INDEX_MIGRATIONS = [
        'Modules/Products/Database/Migrations/2026_11_12_100000_add_performance_indexes_to_products_table.php',
        'Modules/Orders/Database/Migrations/2026_11_12_100001_add_performance_indexes_to_orders_table.php',
    ];

    public function handle(Performance $performance): int
    {
        // Maintenance command: read from the PRIMARY only. The read replica may still be
        // applying the previous big change (replication lag) and would return old counts / ids.
        DB::connection()->recordsHaveBeenModified();
        $state = $performance->state();
        $state['indexes'] = self::indexesExist();

        $preset = $this->argument('preset');
        if ($preset !== null) {
            if (! in_array($preset, ['baseline', 'optimized'], true)) {
                $this->error('Unknown preset. Use: baseline | optimized');

                return self::INVALID;
            }
            foreach (Performance::FEATURES as $feature) {
                $state[$feature] = $preset === 'optimized';
            }
        }

        foreach (['cache' => 'cache', 'etag' => 'etag', 'eager' => 'eager_loading', 'indexes' => 'indexes', 'load-test' => 'load_test'] as $option => $feature) {
            $value = $this->option($option);
            if ($value !== null) {
                if (! in_array($value, ['on', 'off'], true)) {
                    $this->error("--$option must be on or off");

                    return self::INVALID;
                }
                $state[$feature] = $value === 'on';
            }
        }

        $this->applyIndexes($state['indexes']);
        $performance->save($state);

        // New mode = new data shape: start with an empty products cache (new version).
        Performance::store()->forever('products:version', Str::random(12));

        $this->table(['optimization', 'state'], [
            ['Layer 1  ETag / 304 Not Modified', $this->label($state['etag'])],
            ['Layer 2  Redis cache (products, categories, report)', $this->label($state['cache'])],
            ['Layer 3  indexes (products, orders)', $this->label($state['indexes'])],
            ['Layer 3  eager loading (no N+1 on orders)', $this->label($state['eager_loading'])],
            ['Layer 4  read replica', ReadYourWrites::hasReplica()
                ? '<info>ON</info> (always on: reads -> '.config('database.connections.mysql.read.host.0').')' : '<comment>no replica configured</comment>'],
            ['API rate limit (requests / minute)', number_format(Performance::apiRateLimit()).($state['load_test'] ? ' (load test)' : '')],
        ]);

        return self::SUCCESS;
    }

    public static function indexesExist(): bool
    {
        return Schema::hasIndex('products', 'products_price_index');
    }

    private function applyIndexes(bool $wanted): void
    {
        if ($wanted === self::indexesExist()) {
            return;
        }

        if ($wanted) {
            $this->line('Creating the performance indexes (migrate)...');
            foreach (self::INDEX_MIGRATIONS as $path) {
                Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            }

            return;
        }

        $this->line('Dropping the performance indexes (the migrations\' down())...');
        $repository = app('migration.repository');
        foreach (array_reverse(self::INDEX_MIGRATIONS) as $path) {
            $migration = require base_path($path);
            $migration->down();
            $repository->delete((object) ['migration' => basename($path, '.php')]);
        }
    }

    private function label(bool $on): string
    {
        return $on ? '<info>ON</info>' : '<comment>OFF</comment>';
    }
}
