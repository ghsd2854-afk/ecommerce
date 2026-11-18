<?php

namespace Modules\Products\Database\Seeders;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Requirement 3: a realistic catalog size (e.g. 100,000 products) so that slow queries
 * really show up in EXPLAIN and in the load test. Names start with "Perf ".
 */
class PerformanceProductsSeeder
{
    private const CATEGORIES = [
        'Accessories', 'Audio', 'Cameras', 'Displays', 'Gaming', 'Home',
        'Kitchen', 'Networking', 'Office', 'Phones', 'Storage', 'Wearables',
    ];

    private const ADJECTIVES = ['Smart', 'Portable', 'Wireless', 'Compact', 'Pro', 'Ultra', 'Classic', 'Mini', 'Max', 'Eco'];

    private const NOUNS = ['Speaker', 'Charger', 'Cable', 'Stand', 'Lamp', 'Router', 'Drive', 'Watch', 'Camera', 'Keyboard', 'Mouse', 'Kettle'];

    /**
     * @param  Closure(int): void  $progress  called with the number of rows inserted
     * @return array{ids: array<int,int>, names: array<int,string>, prices: array<int,float>} all Perf products
     */
    public function seed(int $count, Closure $progress): array
    {
        $existing = DB::table('products')->where('name', 'like', 'Perf %')->count();
        $now = now();

        for ($start = $existing + 1; $start <= $count; $start += 1000) {
            $rows = [];
            for ($i = $start; $i < min($start + 1000, $count + 1); $i++) {
                $name = 'Perf '.self::ADJECTIVES[mt_rand(0, 9)].' '.self::NOUNS[mt_rand(0, 11)].' '.$i;
                $rows[] = [
                    'name' => $name,
                    'description' => "Generated product #$i for the performance tests.",
                    'price' => mt_rand(100, 200000) / 100,
                    'category' => self::CATEGORIES[mt_rand(0, 11)],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('products')->insert($rows);
            $progress(count($rows));
        }

        $all = DB::table('products')->where('name', 'like', 'Perf %')->get(['id', 'name', 'price']);

        return [
            'ids' => $all->pluck('id')->map(fn ($v) => (int) $v)->all(),
            'names' => $all->pluck('name')->all(),
            'prices' => $all->pluck('price')->map(fn ($v) => (float) $v)->all(),
        ];
    }

    public function clean(): int
    {
        $deleted = 0;
        do {
            $n = DB::table('products')->where('name', 'like', 'Perf %')->limit(10000)->delete();
            $deleted += $n;
        } while ($n > 0);

        return $deleted;
    }
}
