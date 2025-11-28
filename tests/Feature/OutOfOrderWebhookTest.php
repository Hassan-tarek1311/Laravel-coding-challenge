<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\WebhookIdempotency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutOfOrderWebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test out-of-order webhook - webhook arrives BEFORE order is created
     * System must handle and end with correct final state
     */
    public function test_out_of_order_webhook(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 99.99,
            'total_stock' => 100,
        ]);

        // Create hold but don't create order yet
        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 1,
            'expires_at' => now()->addMinutes(2),
            'status' => 'active',
        ]);

        $idempotencyKey = 'test-out-of-order-' . uniqid();
        $nonExistentOrderId = 99999;

        // Webhook arrives before order is created
        $response1 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $nonExistentOrderId,
            'status' => 'paid',
            'provider_payload' => ['transaction_id' => 'txn_123'],
        ]);

        // Should accept the webhook and record it
        $response1->assertStatus(202);
        $response1->assertJson([
            'message' => 'Order not found, webhook recorded',
            'result_state' => 'order_not_found',
        ]);

        // Verify idempotency record exists
        $this->assertDatabaseHas('webhook_idempotency', [
            'idempotency_key' => $idempotencyKey,
            'result_state' => 'order_not_found',
        ]);

        // Now create the order
        $hold->markAsUsed();
        $order = Order::create([
            'hold_id' => $hold->id,
            'product_id' => $product->id,
            'qty' => 1,
            'status' => 'pending_payment',
        ]);

        // Send webhook again with same idempotency key but now order exists
        // This should be idempotent - return the previous result
        $response2 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $order->id,
            'status' => 'paid',
            'provider_payload' => ['transaction_id' => 'txn_123'],
        ]);

        // Should return idempotent response
        $response2->assertStatus(200);
        $response2->assertJson([
            'message' => 'Webhook already processed',
        ]);

        // Order should still be pending_payment (not updated because webhook was idempotent)
        $order->refresh();
        $this->assertEquals('pending_payment', $order->status);

        // However, if we send a NEW webhook with different idempotency key, it should work
        $newIdempotencyKey = 'test-new-key-' . uniqid();
        $response3 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $newIdempotencyKey,
            'order_id' => $order->id,
            'status' => 'paid',
            'provider_payload' => ['transaction_id' => 'txn_456'],
        ]);

        $response3->assertStatus(200);
        $order->refresh();
        $this->assertEquals('paid', $order->status);
    }
}
