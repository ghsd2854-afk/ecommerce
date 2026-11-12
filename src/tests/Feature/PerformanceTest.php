<?php

namespace Tests\Feature;

use App\Support\Performance;
use App\Support\ReadYourWrites;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Orders\Models\Order;
use Modules\Users\Models\OtpCode;
use Modules\Users\Models\User;

/**
 * Requirement 3: cache, invalidation, ETag / 304, N+1, sales report, read-your-writes.
 * (The real Redis / MySQL replica / k6 numbers are shown with: demo.cmd perf | replica | explain)
 */
class PerformanceTest extends FeatureTestCase
{
    /** Runs $callback and returns the SQL queries it executed. */
    private function queries(callable $callback): array
    {
        $queries = [];
        DB::listen(function (QueryExecuted $q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $callback();
        app('events')->forget(QueryExecuted::class);

        return $queries;
    }

    private function productQueries(array $queries): array
    {
        return array_values(array_filter($queries, fn ($sql) => str_contains($sql, '"products"') || str_contains($sql, '`products`')));
    }

    public function test_product_list_is_served_from_the_cache_without_database_queries(): void
    {
        $first = $this->queries(fn () => $this->api('GET', '/products')->assertOk());
        $second = $this->queries(fn () => $this->api('GET', '/products')->assertOk());

        $this->assertNotEmpty($this->productQueries($first), 'first request reads the database');
        $this->assertSame([], $this->productQueries($second), 'second request must come from the cache');
    }

    public function test_product_details_are_cached_but_the_stock_is_always_live(): void
    {
        $this->api('GET', "/products/{$this->mouse->id}")->assertJsonPath('data.stock.available_quantity', 50);

        $this->inventory->stock[$this->mouse->id] = 7; // an order elsewhere changed the stock

        $queries = $this->queries(function () {
            $this->api('GET', "/products/{$this->mouse->id}")
                ->assertJsonPath('data.name', 'Wireless Mouse')
                ->assertJsonPath('data.stock.available_quantity', 7);
        });
        $this->assertSame([], $this->productQueries($queries), 'product data comes from the cache, stock from the inventory');
    }

    public function test_a_product_change_is_visible_immediately_despite_the_cache(): void
    {
        $this->api('GET', '/products')->assertJsonFragment(['name' => 'Wireless Mouse']);
        $this->api('GET', "/products/{$this->mouse->id}")->assertJsonPath('data.price', '19.99');
        $this->api('GET', '/products/categories')->assertJsonMissing(['Cameras']);

        $this->api('PATCH', "/products/{$this->mouse->id}", ['name' => 'Silent Mouse', 'price' => 17.50], $this->admin)->assertOk();
        $this->api('POST', '/products', ['name' => 'Action Cam', 'price' => 99, 'category' => 'Cameras'], $this->admin)->assertCreated();

        $this->api('GET', '/products')
            ->assertJsonFragment(['name' => 'Silent Mouse'])
            ->assertJsonMissing(['name' => 'Wireless Mouse']);
        $this->api('GET', "/products/{$this->mouse->id}")
            ->assertJsonPath('data.name', 'Silent Mouse')
            ->assertJsonPath('data.price', '17.50');
        $this->assertContains('Cameras', $this->api('GET', '/products/categories')->json('data'));
    }

    public function test_a_deleted_product_disappears_immediately(): void
    {
        $this->api('GET', "/products/{$this->speaker->id}")->assertOk();

        $this->api('DELETE', "/products/{$this->speaker->id}", [], $this->admin)->assertNoContent();

        $this->api('GET', "/products/{$this->speaker->id}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->api('GET', '/products')->assertJsonMissing(['name' => 'Bluetooth Speaker']);
    }

    public function test_etag_gives_304_when_nothing_changed_and_200_after_a_change(): void
    {
        $first = $this->api('GET', "/products/{$this->mouse->id}")->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('no-cache', $first->headers->get('Cache-Control'));

        $notModified = $this->withHeaders(['If-None-Match' => $etag])->getJson("/api/v1/products/{$this->mouse->id}");
        $notModified->assertStatus(304);
        $this->assertSame('', $notModified->getContent(), '304 has no body');

        // Price changed -> the old ETag does not match any more -> full 200 response.
        $this->api('PATCH', "/products/{$this->mouse->id}", ['price' => 25], $this->admin)->assertOk();
        $changed = $this->withHeaders(['If-None-Match' => $etag])->getJson("/api/v1/products/{$this->mouse->id}");
        $changed->assertOk()->assertJsonPath('data.price', '25.00');
        $this->assertNotSame($etag, $changed->headers->get('ETag'));

        // A stock change also changes the ETag (the stock is part of the response).
        $etag = $changed->headers->get('ETag');
        $this->inventory->stock[$this->mouse->id] = 3;
        $this->withHeaders(['If-None-Match' => $etag])->getJson("/api/v1/products/{$this->mouse->id}")->assertOk();
    }

    public function test_etag_on_the_list_and_private_cache_control_on_orders(): void
    {
        $etag = $this->api('GET', '/products')->headers->get('ETag');
        $this->withHeaders(['If-None-Match' => $etag])->getJson('/api/v1/products')->assertStatus(304);

        $this->orderOf($this->customer);
        $orders = $this->api('GET', '/orders', [], $this->customer)->assertOk();
        $this->assertStringContainsString('private', $orders->headers->get('Cache-Control'));
        $this->assertNotEmpty($orders->headers->get('ETag'));
    }

    public function test_orders_list_has_no_n_plus_1_queries(): void
    {
        $this->orderOf($this->customer);
        $one = $this->queries(fn () => $this->api('GET', '/orders', [], $this->customer)->assertJsonCount(1, 'data'));

        for ($i = 0; $i < 6; $i++) {
            $this->orderOf($this->customer);
        }
        $seven = $this->queries(fn () => $this->api('GET', '/orders', [], $this->customer)->assertJsonCount(7, 'data'));

        $this->assertCount(count($one), $seven, 'the number of queries must not grow with the number of orders');
        $this->assertCount(1, array_filter($seven, fn ($sql) => str_contains($sql, 'order_items')), 'ONE query for the items of all orders');
    }

    public function test_baseline_mode_switches_the_optimizations_off(): void
    {
        app(Performance::class)->save(['cache' => false, 'etag' => false, 'eager_loading' => false]);

        $this->assertNull($this->api('GET', '/products')->headers->get('ETag'));
        $this->assertNotEmpty($this->productQueries($this->queries(fn () => $this->api('GET', '/products'))), 'no cache');

        $this->orderOf($this->customer);
        $one = count($this->queries(fn () => $this->api('GET', '/orders', [], $this->customer)));
        $this->orderOf($this->customer);
        $this->orderOf($this->customer);
        $three = count($this->queries(fn () => $this->api('GET', '/orders', [], $this->customer)));
        $this->assertSame($one + 2, $three, 'N+1: one extra query per order');

        app(Performance::class)->save(Performance::defaults());
        $this->assertNotNull($this->api('GET', '/products')->headers->get('ETag'));
    }

    public function test_sales_report_is_admin_only_and_skips_cancelled_orders(): void
    {
        Order::create(['user_id' => $this->customer->id, 'status' => 'delivered', 'total' => 100]);
        Order::create(['user_id' => $this->customer->id, 'status' => 'pending', 'total' => 50.5]);
        Order::create(['user_id' => $this->customer->id, 'status' => 'cancelled', 'total' => 999]);
        $old = Order::create(['user_id' => $this->customer->id, 'status' => 'delivered', 'total' => 70]);
        $old->forceFill(['created_at' => now()->subDays(60)])->save();

        $this->api('GET', '/orders/stats', [], $this->customer)->assertForbidden();

        $this->api('GET', '/orders/stats?days=30', [], $this->admin)
            ->assertOk()
            ->assertJsonPath('data.days', 30)
            ->assertJsonPath('data.orders', 2)
            ->assertJsonPath('data.revenue', 150.5);

        $this->api('GET', '/orders/stats?days=500', [], $this->admin)->assertUnprocessable();
    }

    public function test_after_a_write_reads_go_to_the_primary_when_a_replica_is_configured(): void
    {
        // Pretend reads and writes go to different servers (the tests use one SQLite file).
        config([
            'database.connections.sqlite.read' => ['host' => ['db-replica']],
            'database.connections.sqlite.write' => ['host' => ['db']],
        ]);

        $this->assertFalse(ReadYourWrites::mustReadPrimary('products'), 'no write yet: replica');

        $this->api('PATCH', "/products/{$this->mouse->id}", ['name' => 'New Name'], $this->admin)->assertOk();
        $this->assertTrue(ReadYourWrites::mustReadPrimary('products'), 'just written: primary');

        $this->api('POST', '/orders', ['items' => [['product_id' => $this->mouse->id, 'quantity' => 1]]], $this->customer)->assertCreated();
        $this->assertTrue(ReadYourWrites::mustReadPrimary('user:'.$this->customer->id));
        $this->assertFalse(ReadYourWrites::mustReadPrimary('user:'.$this->customer2->id), 'other users are not affected');
    }

    public function test_the_rate_limit_can_only_be_lifted_in_the_local_environment(): void
    {
        app(Performance::class)->save(['load_test' => true]);

        $this->assertSame(60, Performance::apiRateLimit(), 'APP_ENV=testing: limit stays');
    }

    public function test_the_n_plus_1_detector_fails_on_lazy_loading_in_a_list(): void
    {
        // The whole test suite runs with this detector on: any endpoint with an N+1 query fails.
        $this->orderOf($this->customer);
        $this->orderOf($this->customer2);

        $this->expectException(LazyLoadingViolationException::class);
        Order::query()->get()->each(fn (Order $order) => $order->items);
    }

    public function test_security_critical_reads_always_use_the_primary(): void
    {
        // Login / token / OTP (a new password or code must work at once) and checkout prices.
        $this->assertTrue(User::query()->getQuery()->useWritePdo, 'accounts');
        $this->assertTrue(OtpCode::query()->getQuery()->useWritePdo, 'OTP codes');
    }
}
