<?php

namespace App\Http\Controllers;

use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HoldController extends Controller
{
    public function __construct(
        private StockService $stockService
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|exists:products,id',
            'qty' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
            ], 422);
        }

        $productId = $request->input('product_id');
        $qty = $request->input('qty');

        $hold = $this->stockService->createHold($productId, $qty);

        if (!$hold) {
            return response()->json([
                'error' => 'Insufficient stock or unable to create hold',
            ], 409);
        }

        return response()->json([
            'hold_id' => $hold->id,
            'expires_at' => $hold->expires_at->toIso8601String(),
        ], 201);
    }
}
