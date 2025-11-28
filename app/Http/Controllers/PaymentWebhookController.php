<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\WebhookIdempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'idempotency_key' => 'required|string',
            'order_id' => 'required|integer',
            'status' => 'required|string|in:paid,cancelled',
            'provider_payload' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        $idempotencyKey = $request->input('idempotency_key');
        $orderId = $request->input('order_id');
        $status = $request->input('status');
        $providerPayload = $request->input('provider_payload', []);

        // Check idempotency
        $existing = WebhookIdempotency::where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            Log::info('Webhook idempotency hit', [
                'idempotency_key' => $idempotencyKey,
                'order_id' => $orderId,
                'previous_state' => $existing->result_state,
            ]);

            return response()->json([
                'message' => 'Webhook already processed',
                'result_state' => $existing->result_state,
            ], 200);
        }

        // Process webhook
        $payloadHash = hash('sha256', json_encode($request->all()));
        
        return DB::transaction(function () use ($idempotencyKey, $orderId, $status, $providerPayload, $payloadHash) {
            // Handle out-of-order webhook (order might not exist yet)
            $order = Order::lockForUpdate()->find($orderId);

            if (!$order) {
                // Store idempotency record for out-of-order webhook
                WebhookIdempotency::create([
                    'idempotency_key' => $idempotencyKey,
                    'processed_at' => now(),
                    'payload_hash' => $payloadHash,
                    'result_state' => 'order_not_found',
                ]);

                Log::warning('Webhook received for non-existent order', [
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $orderId,
                ]);

                return response()->json([
                    'message' => 'Order not found, webhook recorded',
                    'result_state' => 'order_not_found',
                ], 202);
            }

            // Update order status
            $order->status = $status;
            $order->payment_meta = $providerPayload;
            $order->save();

            // If cancelled, release stock (hold is already marked as used, so we just need to clear cache)
            if ($status === 'cancelled') {
                // The stock is already released from the hold, but we need to ensure
                // the cache is cleared so availability is recalculated
                \Illuminate\Support\Facades\Cache::forget("product:{$order->product_id}:available_stock");
                
                Log::info('Order cancelled, stock released', [
                    'order_id' => $orderId,
                    'product_id' => $order->product_id,
                ]);
            }

            // Store idempotency record
            WebhookIdempotency::create([
                'idempotency_key' => $idempotencyKey,
                'processed_at' => now(),
                'payload_hash' => $payloadHash,
                'result_state' => $status,
            ]);

            Log::info('Webhook processed successfully', [
                'idempotency_key' => $idempotencyKey,
                'order_id' => $orderId,
                'status' => $status,
            ]);

            return response()->json([
                'message' => 'Webhook processed',
                'order_id' => $order->id,
                'status' => $order->status,
            ], 200);
        });
    }
}
