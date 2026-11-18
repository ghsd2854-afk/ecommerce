<?php

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;

/**
 * Requirement 1 - Unified error format.
 * EVERY API error has the same shape: {"status", "code", "message"} (+ "errors" for
 * validation, + "details" for extra data), the body status equals the HTTP status,
 * and no internal information (stack trace, file, exception class) is exposed.
 * Run: php artisan test --filter=ErrorFormatTest
 */
class ErrorFormatTest extends FeatureTestCase
{
    private function assertUnifiedError(TestResponse $response, int $status, string $code): TestResponse
    {
        $response->assertStatus($status)
            ->assertJsonPath('status', $status)
            ->assertJsonPath('code', $code)
            ->assertJsonStructure(['status', 'code', 'message'])
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('file');

        $this->assertIsString($response->json('message'));
        $this->assertNotSame('', $response->json('message'));

        return $response;
    }

    public function test_401_missing_token(): void
    {
        $this->assertUnifiedError($this->json('GET', '/api/v1/orders'), 401, 'UNAUTHENTICATED');
    }

    public function test_401_wrong_credentials(): void
    {
        $this->assertUnifiedError(
            $this->json('POST', '/api/v1/auth/login', ['email' => 'customer@shop.test', 'password' => 'WrongPassword1']),
            401, 'INVALID_CREDENTIALS');
    }

    public function test_403_another_users_order(): void
    {
        $order = $this->orderOf($this->customer);

        $this->assertUnifiedError($this->api('GET', "/orders/{$order->id}", as: $this->customer2), 403, 'FORBIDDEN')
            ->assertJsonPath('message', 'You are not allowed to access this order.');
    }

    public function test_403_admin_only_action(): void
    {
        $this->assertUnifiedError(
            $this->api('POST', '/products', ['name' => 'X', 'price' => 1, 'category' => 'X'], $this->customer),
            403, 'FORBIDDEN');
    }

    public function test_403_unverified_email(): void
    {
        $user = $this->makeUser('New', 'new@shop.test', 'customer');
        $user->forceFill(['email_verified_at' => null])->save();

        $this->assertUnifiedError(
            $this->json('POST', '/api/v1/auth/login', ['email' => 'new@shop.test', 'password' => 'Password123']),
            403, 'EMAIL_NOT_VERIFIED');
    }

    public function test_404_unknown_resource_does_not_reveal_internals(): void
    {
        $this->assertUnifiedError($this->json('GET', '/api/v1/products/999999'), 404, 'NOT_FOUND')
            ->assertJsonPath('message', 'Resource not found.');

        $this->assertUnifiedError($this->json('GET', '/api/v1/no-such-endpoint'), 404, 'NOT_FOUND');
    }

    public function test_405_wrong_method(): void
    {
        $this->assertUnifiedError($this->api('PUT', '/orders', [], $this->customer), 405, 'METHOD_NOT_ALLOWED');
    }

    public function test_409_insufficient_stock_with_details(): void
    {
        $this->assertUnifiedError(
            $this->api('POST', '/orders', ['items' => [['product_id' => $this->speaker->id, 'quantity' => 3]]], $this->customer),
            409, 'INSUFFICIENT_STOCK')
            ->assertJsonStructure(['details' => ['shortages' => [['product_id', 'requested', 'available']]]]);
    }

    public function test_409_product_in_use(): void
    {
        $this->orderOf($this->customer);

        $this->assertUnifiedError($this->api('DELETE', "/products/{$this->mouse->id}", [], $this->admin), 409, 'PRODUCT_IN_USE');
    }

    public function test_422_validation_with_field_errors(): void
    {
        $this->assertUnifiedError(
            $this->api('POST', '/products', ['name' => '', 'price' => -1], $this->admin),
            422, 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['name', 'price', 'category']]);
    }

    public function test_422_invalid_otp_code(): void
    {
        $this->assertUnifiedError(
            $this->json('POST', '/api/v1/auth/verify-email', ['email' => 'customer@shop.test', 'code' => '123456']),
            422, 'INVALID_CODE');
    }

    public function test_429_too_many_attempts_with_retry_after(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->json('POST', '/api/v1/auth/login', ['email' => 'customer@shop.test', 'password' => 'WrongPassword1']);
        }

        $this->assertUnifiedError(
            $this->json('POST', '/api/v1/auth/login', ['email' => 'customer@shop.test', 'password' => 'WrongPassword1']),
            429, 'TOO_MANY_REQUESTS')
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['details' => ['retry_after_seconds']]);
    }

    public function test_503_inventory_unavailable(): void
    {
        $this->inventory->failure = 'down';

        $this->assertUnifiedError(
            $this->api('POST', '/orders', ['items' => [['product_id' => $this->mouse->id, 'quantity' => 1]]], $this->customer),
            503, 'INVENTORY_UNAVAILABLE');
    }

    public function test_504_inventory_timeout(): void
    {
        $this->inventory->failure = 'slow';

        $this->assertUnifiedError(
            $this->api('POST', '/orders', ['items' => [['product_id' => $this->mouse->id, 'quantity' => 1]]], $this->customer),
            504, 'INVENTORY_TIMEOUT');
    }

    public function test_500_order_not_saved(): void
    {
        config(['inventory.simulate_order_save_failure' => true]);
        $this->app['env'] = 'local';

        $this->assertUnifiedError(
            $this->api('POST', '/orders', ['items' => [['product_id' => $this->mouse->id, 'quantity' => 1]]], $this->customer),
            500, 'ORDER_NOT_SAVED');
    }

    public function test_500_unexpected_error_hides_the_stack_trace(): void
    {
        $this->inventory->failure = 'crash';

        $this->assertUnifiedError($this->json('GET', "/api/v1/products/{$this->mouse->id}"), 500, 'INTERNAL_ERROR')
            ->assertJsonPath('message', 'An unexpected error occurred. Please try again later.');
    }
}
