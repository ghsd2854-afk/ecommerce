<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Contracts\InventoryGateway;
use Modules\Orders\Models\Order;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;
use Tests\Fakes\FakeInventoryGateway;
use Tests\TestCase;

/**
 * Shared setup: an in-memory database (SQLite), three accounts, two products
 * and the fake inventory service in place of the gRPC client.
 */
abstract class FeatureTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $customer;

    protected User $customer2;

    protected Product $mouse;

    protected Product $speaker;

    protected FakeInventoryGateway $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('Admin', 'admin@shop.test', 'admin');
        $this->customer = $this->makeUser('Test Customer', 'customer@shop.test', 'customer');
        $this->customer2 = $this->makeUser('Second Customer', 'customer2@shop.test', 'customer');

        $this->mouse = Product::create(['name' => 'Wireless Mouse', 'price' => 19.99, 'category' => 'Accessories']);
        $this->speaker = Product::create(['name' => 'Bluetooth Speaker', 'price' => 44.00, 'category' => 'Audio']);

        $this->inventory = new FakeInventoryGateway();
        $this->inventory->stock = [$this->mouse->id => 50, $this->speaker->id => 1];
        $this->app->instance(InventoryGateway::class, $this->inventory);
    }

    protected function makeUser(string $name, string $email, string $role): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => 'Password123',
            'role' => $role,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    /** JSON request to the versioned API as the given user (no manual login needed). */
    protected function api(string $method, string $uri, array $data = [], ?User $as = null)
    {
        if ($as) {
            $this->actingAs($as, 'api');
        }

        return $this->json($method, '/api/v1'.$uri, $data);
    }

    protected function orderOf(User $user): Order
    {
        $order = Order::create(['user_id' => $user->id, 'status' => 'pending', 'total' => 19.99]);
        $order->items()->create([
            'product_id' => $this->mouse->id,
            'product_name' => $this->mouse->name,
            'quantity' => 1,
            'unit_price' => 19.99,
        ]);

        return $order;
    }
}
