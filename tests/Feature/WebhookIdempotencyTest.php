<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\WebhookIdempotency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test webhook idempotency - send same idempotency_key twice
     * Order state should not change after first update
     */
    public function test_webhook_idempotency(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 99.99,
            'total_stock' => 100,
        ]);

        // Create hold and order
        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 1,
            'expires_at' => now()->addMinutes(2),
            'status' => 'active',
        ]);

        $hold->markAsUsed();

        $order = Order::create([
            'hold_id' => $hold->id,
            'product_id' => $product->id,
            'qty' => 1,
            'status' => 'pending_payment',
        ]);

        $idempotencyKey = 'test-idempotency-key-' . uniqid();

        // First webhook call
        $response1 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $order->id,
            'status' => 'paid',
            'provider_payload' => ['transaction_id' => 'txn_123'],
        ]);

        $response1->assertStatus(200);
        $response1->assertJson([
            'status' => 'paid',
        ]);

        // Verify order is paid
        $order->refresh();
        $this->assertEquals('paid', $order->status);

        // Verify idempotency record exists
        $this->assertDatabaseHas('webhook_idempotency', [
            'idempotency_key' => $idempotencyKey,
            'result_state' => 'paid',
        ]);

        // Second webhook call with same idempotency key
        $response2 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $order->id,
            'status' => 'cancelled', // Try to change status
            'provider_payload' => ['transaction_id' => 'txn_456'],
        ]);

        $response2->assertStatus(200);
        $response2->assertJson([
            'message' => 'Webhook already processed',
        ]);

        // Verify order status did NOT change
        $order->refresh();
        $this->assertEquals('paid', $order->status, 'Order status should not change on duplicate webhook');

        // Verify only one idempotency record exists
        $idempotencyCount = WebhookIdempotency::where('idempotency_key', $idempotencyKey)->count();
        $this->assertEquals(1, $idempotencyCount, 'Should have only one idempotency record');
    }

    /**
     * Test that cancelled orders release stock properly
     */
    public function test_cancelled_order_releases_stock(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 99.99,
            'total_stock' => 100,
        ]);

        // Create hold and order
        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 10,
            'expires_at' => now()->addMinutes(2),
            'status' => 'active',
        ]);

        $hold->markAsUsed();

        $order = Order::create([
            'hold_id' => $hold->id,
            'product_id' => $product->id,
            'qty' => 10,
            'status' => 'pending_payment',
        ]);

        // First, pay the order
        $idempotencyKey1 = 'test-cancel-1-' . uniqid();
        $response1 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey1,
            'order_id' => $order->id,
            'status' => 'paid',
            'provider_payload' => ['transaction_id' => 'txn_123'],
        ]);

        $response1->assertStatus(200);

        // Check available stock after payment (should be reduced)
        \Illuminate\Support\Facades\Cache::flush();
        $response = $this->getJson("/api/products/{$product->id}");
        $response->assertStatus(200);
        $availableAfterPayment = $response->json('available_stock');
        $this->assertEquals(90, $availableAfterPayment);

        // Now cancel the order
        $idempotencyKey2 = 'test-cancel-2-' . uniqid();
        $response2 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey2,
            'order_id' => $order->id,
            'status' => 'cancelled',
            'provider_payload' => ['reason' => 'customer_request'],
        ]);

        $response2->assertStatus(200);

        // Verify order is cancelled
        $order->refresh();
        $this->assertEquals('cancelled', $order->status);

        // Check available stock after cancellation (should be restored)
        \Illuminate\Support\Facades\Cache::flush();
        $response = $this->getJson("/api/products/{$product->id}");
        $response->assertStatus(200);
        $availableAfterCancellation = $response->json('available_stock');
        $this->assertEquals(100, $availableAfterCancellation, 'Stock should be restored after cancellation');
    }
}
