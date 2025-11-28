<?php

namespace App\Http\Controllers;

use App\Models\Hold;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'hold_id' => 'required|integer|exists:holds,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        $holdId = $request->input('hold_id');

        return DB::transaction(function () use ($holdId) {
            $hold = Hold::lockForUpdate()->findOrFail($holdId);

            if ($hold->status !== 'active') {
                Log::warning('Attempted to create order from non-active hold', [
                    'hold_id' => $holdId,
                    'status' => $hold->status,
                ]);
                return response()->json([
                    'error' => 'Hold is not active',
                ], 409);
            }

            if ($hold->isExpired()) {
                $hold->markAsExpired();
                Log::info('Hold expired during order creation', [
                    'hold_id' => $holdId,
                ]);
                return response()->json([
                    'error' => 'Hold has expired',
                ], 409);
            }

            $hold->markAsUsed();

            $order = Order::create([
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id,
                'qty' => $hold->qty,
                'status' => 'pending_payment',
            ]);

            Log::info('Order created successfully', [
                'order_id' => $order->id,
                'hold_id' => $holdId,
            ]);

            return response()->json([
                'order_id' => $order->id,
                'status' => $order->status,
                'product_id' => $order->product_id,
                'qty' => $order->qty,
            ], 201);
        });
    }
}
