<?php

namespace Modules\Orders\Database\Seeders;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Requirement 3: hundreds of thousands of fake orders (each with 1-4 items), spread
 * over the last 365 days with realistic statuses.
 *
 * The Orders module does not read the users / products tables: the command passes the
 * customer ids and a snapshot of the products (id, name, price), exactly like a real
 * order stores product_name and unit_price.
 *
 * Fast: explicit ids (no read-back needed for the items) and one INSERT per 1000 orders.
 */
class PerformanceOrdersSeeder
{
    private const STATUSES = [
        'delivered' => 55, 'shipped' => 10, 'confirmed' => 10, 'pending' => 15, 'cancelled' => 10,
    ];

    /**
     * @param  array<int,int>  $userIds
     * @param  array{ids: array<int,int>, names: array<int,string>, prices: array<int,float>}  $products
     * @param  Closure(int): void  $progress
     */
    public function seed(int $count, array $userIds, array $products, Closure $progress): void
    {
        $statusPool = [];
        foreach (self::STATUSES as $status => $weight) {
            array_push($statusPool, ...array_fill(0, $weight, $status));
        }

        $lastUser = count($userIds) - 1;
        $lastProduct = count($products['ids']) - 1;
        $nextId = (int) DB::table('orders')->max('id') + 1;
        $now = time();

        for ($done = 0; $done < $count; $done += 1000) {
            $orders = [];
            $items = [];

            for ($n = 0; $n < min(1000, $count - $done); $n++) {
                $orderId = $nextId++;
                // Slightly more orders in recent months (like a growing shop).
                $daysAgo = (int) floor(365 * (mt_rand(0, 1000) / 1000) ** 1.2);
                $created = date('Y-m-d H:i:s', $now - $daysAgo * 86400 - mt_rand(0, 86399));
                $total = 0;

                for ($k = mt_rand(1, 4); $k > 0; $k--) {
                    $p = mt_rand(0, $lastProduct);
                    $quantity = mt_rand(1, 3);
                    $price = $products['prices'][$p];
                    $total += $price * $quantity;
                    $items[] = [
                        'order_id' => $orderId,
                        'product_id' => $products['ids'][$p],
                        'product_name' => $products['names'][$p],
                        'quantity' => $quantity,
                        'unit_price' => $price,
                        'created_at' => $created,
                        'updated_at' => $created,
                    ];
                }

                $orders[] = [
                    'id' => $orderId,
                    'user_id' => $userIds[mt_rand(0, $lastUser)],
                    'status' => $statusPool[mt_rand(0, 99)],
                    'total' => round($total, 2),
                    'created_at' => $created,
                    'updated_at' => $created,
                ];
            }

            DB::transaction(function () use ($orders, $items) {
                DB::table('orders')->insert($orders);
                foreach (array_chunk($items, 1000) as $chunk) {
                    DB::table('order_items')->insert($chunk);
                }
            });

            $progress(count($orders));
        }
    }

    /** Deletes the orders (and their items) of the given customers. */
    public function clean(array $userIds): int
    {
        $deleted = 0;
        foreach (array_chunk($userIds, 200) as $users) {
            DB::table('orders')->whereIn('user_id', $users)->select('id')->orderBy('id')
                ->chunkById(5000, function ($orders) use (&$deleted) {
                    $ids = $orders->pluck('id')->all();
                    DB::table('order_items')->whereIn('order_id', $ids)->delete();
                    $deleted += DB::table('orders')->whereIn('id', $ids)->delete();
                });
        }

        return $deleted;
    }
}
