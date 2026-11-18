<?php

namespace Tests\Feature;

/**
 * Requirement 1 - API design: versioning, pagination, filtering, status codes,
 * unified error format and security headers.
 * Run: php artisan test --filter=ApiDesignTest
 */
class ApiDesignTest extends FeatureTestCase
{
    public function test_routes_are_versioned_in_the_path(): void
    {
        $this->json('GET', '/api/v1/products')->assertOk();
        $this->json('GET', '/api/products')->assertStatus(404);
    }

    public function test_products_are_paginated_and_filtered_by_category(): void
    {
        $this->json('GET', '/api/v1/products?per_page=1&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 2);

        $this->json('GET', '/api/v1/products?category=Audio')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Bluetooth Speaker');
    }

    public function test_validation_errors_use_the_unified_format(): void
    {
        $this->api('POST', '/orders', ['items' => [['product_id' => 999999, 'quantity' => 0]]], $this->customer)
            ->assertStatus(422)
            ->assertJsonPath('status', 422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['status', 'code', 'message', 'errors' => ['items.0.product_id', 'items.0.quantity']]);
    }

    public function test_not_found_and_wrong_method_are_json_errors(): void
    {
        $this->json('GET', '/api/v1/products/999999')->assertStatus(404)->assertJson(['message' => 'Resource not found.']);
        $this->api('PUT', '/orders', [], $this->customer)->assertStatus(405)->assertJson(['message' => 'Method not allowed.']);
    }

    public function test_security_headers_are_sent(): void
    {
        $this->json('GET', '/api/v1/products')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_product_used_in_an_order_cannot_be_deleted(): void
    {
        $this->orderOf($this->customer); // uses the mouse

        $this->api('DELETE', "/products/{$this->mouse->id}", [], $this->admin)->assertStatus(409);
        $this->api('DELETE', "/products/{$this->speaker->id}", [], $this->admin)->assertStatus(204);
    }
}
