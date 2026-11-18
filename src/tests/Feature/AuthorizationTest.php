<?php

namespace Tests\Feature;

/**
 * Requirement 1 - Security: every user only reaches what they are allowed to.
 * Run: php artisan test --filter=AuthorizationTest
 */
class AuthorizationTest extends FeatureTestCase
{
    public function test_request_without_token_is_rejected(): void
    {
        $this->json('GET', '/api/v1/orders')
            ->assertStatus(401)
            ->assertJsonStructure(['message']);
    }

    public function test_forged_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIiwicm9sZSI6ImFkbWluIn0.forged')
            ->json('GET', '/api/v1/orders')
            ->assertStatus(401);
    }

    public function test_customer_cannot_read_another_customers_order(): void
    {
        $order = $this->orderOf($this->customer);

        $this->api('GET', "/orders/{$order->id}", as: $this->customer2)
            ->assertStatus(403)
            ->assertJson(['message' => 'You are not allowed to access this order.']);
    }

    public function test_owner_and_admin_can_read_the_order(): void
    {
        $order = $this->orderOf($this->customer);

        $this->api('GET', "/orders/{$order->id}", as: $this->customer)->assertOk();
        $this->api('GET', "/orders/{$order->id}", as: $this->admin)->assertOk();
    }

    public function test_customer_sees_only_his_own_orders_and_admin_sees_all(): void
    {
        $mine = $this->orderOf($this->customer);
        $other = $this->orderOf($this->customer2);

        $ids = collect($this->api('GET', '/orders', as: $this->customer)->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($other->id));

        $all = collect($this->api('GET', '/orders', as: $this->admin)->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($all->contains($mine->id) && $all->contains($other->id));
    }

    public function test_customer_cannot_change_order_status(): void
    {
        $order = $this->orderOf($this->customer);

        $this->api('PATCH', "/orders/{$order->id}/status", ['status' => 'delivered'], $this->customer)
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_admin_changes_status_and_customer_is_notified(): void
    {
        $order = $this->orderOf($this->customer);

        $this->api('PATCH', "/orders/{$order->id}/status", ['status' => 'shipped'], $this->admin)
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped');

        $this->assertDatabaseHas('user_notifications', ['user_id' => $this->customer->id, 'type' => 'order_status']);
    }

    public function test_customer_cannot_manage_products(): void
    {
        $this->api('POST', '/products', ['name' => 'X', 'price' => 1, 'category' => 'X'], $this->customer)->assertStatus(403);
        $this->api('PATCH', "/products/{$this->mouse->id}", ['price' => 1], $this->customer)->assertStatus(403);
        $this->api('DELETE', "/products/{$this->mouse->id}", [], $this->customer)->assertStatus(403);
    }

    public function test_admin_can_create_a_product(): void
    {
        $this->api('POST', '/products', ['name' => 'Webcam', 'price' => 39.9, 'category' => 'Accessories'], $this->admin)
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Webcam');
    }

    public function test_customer_cannot_mark_another_users_notification(): void
    {
        $order = $this->orderOf($this->customer);
        $this->api('PATCH', "/orders/{$order->id}/status", ['status' => 'confirmed'], $this->admin);
        $notificationId = \Modules\Notifications\Models\UserNotification::where('user_id', $this->customer->id)->value('id');

        $this->api('PATCH', "/notifications/{$notificationId}/read", [], $this->customer2)->assertStatus(403);
    }

    public function test_unverified_account_cannot_log_in(): void
    {
        $user = $this->makeUser('New', 'new@shop.test', 'customer');
        $user->forceFill(['email_verified_at' => null])->save();

        $this->json('POST', '/api/v1/auth/login', ['email' => 'new@shop.test', 'password' => 'Password123'])
            ->assertStatus(403);
    }

    public function test_logout_invalidates_the_token(): void
    {
        $token = $this->json('POST', '/api/v1/auth/login', ['email' => 'customer@shop.test', 'password' => 'Password123'])
            ->assertOk()
            ->json('access_token');

        $this->withHeader('Authorization', "Bearer $token")->json('POST', '/api/v1/auth/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer $token")->json('GET', '/api/v1/auth/me')->assertStatus(401);
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->json('POST', '/api/v1/auth/login', ['email' => 'customer@shop.test', 'password' => 'WrongPassword1'])
                ->assertStatus(401);
        }

        $this->json('POST', '/api/v1/auth/login', ['email' => 'customer@shop.test', 'password' => 'WrongPassword1'])
            ->assertStatus(429);

        // Even the correct password is refused for this account during the minute.
        $this->json('POST', '/api/v1/auth/login', ['email' => 'customer@shop.test', 'password' => 'Password123'])
            ->assertStatus(429);
    }

    public function test_rate_limit_blocks_only_the_attacked_account(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->json('POST', '/api/v1/auth/login', ['email' => 'customer@shop.test', 'password' => 'WrongPassword1']);
        }

        // Another user, from the same address, logs in normally.
        $this->json('POST', '/api/v1/auth/login', ['email' => 'customer2@shop.test', 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonStructure(['access_token']);
    }
}
