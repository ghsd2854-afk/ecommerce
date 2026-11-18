<?php

namespace Modules\Orders\Services;

use App\Support\Performance;
use Modules\Orders\Models\Order;
use Throwable;

/**
 * Admin sales report: number of orders and revenue per day for the last N days.
 *
 * - Heavy query on a big table -> runs on the READ REPLICA (reports are the classic
 *   replica workload: they do not slow down the primary that takes the new orders).
 * - Fast thanks to the covering index (created_at, status, total).
 * - Cached in Redis with a short TTL (config performance.ttl.sales_report). It is NOT
 *   invalidated on every new order on purpose: with many orders per second the cache
 *   would be useless. A report that is up to one minute old is acceptable;
 *   a product price or a stock quantity that is one minute old is NOT.
 */
class SalesReport
{
    public function lastDays(int $days): array
    {
        if (! Performance::on('cache')) {
            return $this->build($days);
        }

        try {
            return Performance::store()->remember(
                "orders:sales_report:{$days}:".now()->toDateString(),
                (int) config('performance.ttl.sales_report', 60),
                fn () => $this->build($days)
            );
        } catch (Throwable $e) {
            report($e);

            return $this->build($days);
        }
    }

    private function build(int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $perDay = Order::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as orders, SUM(total) as revenue')
            ->where('created_at', '>=', $from)
            ->where('status', '<>', 'cancelled')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
            ]);

        return [
            'from' => $from->toDateString(),
            'days' => $days,
            'orders' => $perDay->sum('orders'),
            'revenue' => round($perDay->sum('revenue'), 2),
            'per_day' => $perDay->values()->all(),
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
