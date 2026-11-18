<?php

namespace Tests\Feature;

use Modules\Orders\Models\Order;

/**
 * Requirement 2 - Inter-service communication with the inventory service.
 * The gRPC client is replaced by an in-memory fake that behaves the same way.
 * Run: php artisan test --filter=InventoryTest
 */
class InventoryTest extends FeatureTestCase
{
    public function test_product_details_show_the_stock_from_the_inventory_service(): void
    {
        $this->json('GET', "/api/v1/products/{$this->mouse->id}")
            ->assertOk()
            ->assertJsonPath('data.stock.available_quantity', 50)
            ->assertJsonPath('data.stock.in_stock', true);
    }

    public function test_product_details_still_work_when_the_inventory_service_is_down(): void
    {
        $this->inventory->failure = 'down';

        $this->json('GET', "/api/v1/products/{$this->mouse->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Wireless Mouse')
            ->assertJsonPath('data.stock.available_quantity', null);
    }

    public function test_order_is_created_when_the_stock_is_reserved(): void
    {
        $this->api('POST', '/orders', ['items' => [['product_id' => $this->mouse->id, 'quantity' => 2]]], $this->customer)
            ->assertStatus(201)
            ->assertJsonPath('data.items.0.product_name', 'Wireless Mouse');

        $this->assertSame(48, $this->inventory->stock[$this->mouse->id]);
        $this->assertNotNull(Order::first()->reservation_id);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $this->customer->id, 'type' => 'order_created']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $this->admin->id, 'type' => 'new_order']);
    }

    public function test_order_is_rejected_when_the_stock_is_insufficient(): void
    {
        $this->api('POST', '/orders', ['items' => [['product_id' => $this->speaker->id, 'quantity' => 3]]], $this->customer)
            ->assertStatus(409)
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('details.shortages.0.available', 1)
            ->assertJsonStructure(['status', 'code', 'message', 'details' => ['shortages']]);

        $this->assertSame(0, Order::count());
    }

    public function test_reservation_is_all_or_nothing(): void
    {
        $this->api('POST', '/orders', ['items' => [
            ['product_id' => $this->mouse->id, 'quantity' => 1],     // available
            ['product_id' => $this->speaker->id, 'quantity' => 5],   // only 1 left
        ]], $this->customer)->assertStatus(409);

        $this->assertSame(50, $this->inventory->stock[$this->mouse->id], 'the available product must not be reserved');
        $this->assertSame(0, Order::count());
    }

    public function test_inventory_service_down_returns_503_and_creates_nothing(): void
    {
        $this->inventory->failure = 'down';

        $this->api('POST', '/orders', ['items' => [['product_id' => $this->mouse->id, 'quantity' => 1]]], $this->customer)
            ->assertStatus(503)
            ->assertJsonStructure(['message']);

        $this->assertSame(0, Order::count());
    }

    public function test_inventory_service_too_slow_returns_504_and_creates_nothing(): void
    {
        $this->inventory->failure = 'slow';

        $this->api('POST', '/orders', ['items' => [['product_id' => $this->mouse->id, 'quantity' => 1]]], $this->customer)
            ->assertStatus(504)
            ->assertJsonStructure(['message']);

        $this->assertSame(0, Order::count());
    }

    public function test_failed_save_after_reservation_returns_the_stock(): void
    {
        config(['inventory.simulate_order_save_failure' => true]);
        $this->app['env'] = 'local'; // the simulation switch only works locally

        $this->api('POST', '/orders', ['items' => [['product_id' => $this->mouse->id, 'quantity' => 3]]], $this->customer)
            ->assertStatus(500)
            ->assertJson(['message' => 'The order could not be saved. The reserved stock was returned to the inventory.']);

        $this->assertSame(50, $this->inventory->stock[$this->mouse->id]);
        $this->assertCount(1, $this->inventory->released);
        $this->assertSame(0, Order::count());
    }

    public function test_order_keeps_the_product_name_from_the_time_of_purchase(): void
    {
        $id = $this->api('POST', '/orders', ['items' => [['product_id' => $this->mouse->id, 'quantity' => 1]]], $this->customer)
            ->assertStatus(201)
            ->json('data.id');

        $this->mouse->update(['name' => 'Renamed Mouse']);

        $this->api('GET', "/orders/{$id}", as: $this->customer)
            ->assertJsonPath('data.items.0.product_name', 'Wireless Mouse');
    }
}
