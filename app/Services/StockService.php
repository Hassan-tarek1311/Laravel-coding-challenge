<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Hold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class StockService
{
    /**
     * Calculate available stock for a product
     * available_stock = total_stock - active_holds_qty - paid_orders_qty
     */
    public function getAvailableStock(int $productId): int
    {
        $cacheKey = "product:{$productId}:available_stock";
        
        return Cache::remember($cacheKey, 5, function () use ($productId) {
            $product = Product::findOrFail($productId);
            
            $activeHoldsQty = Hold::where('product_id', $productId)
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->sum('qty');
            
            $paidOrdersQty = DB::table('orders')
                ->where('product_id', $productId)
                ->where('status', 'paid')
                ->sum('qty');
            
            $available = $product->total_stock - $activeHoldsQty - $paidOrdersQty;
            
            return max(0, $available);
        });
    }

    /**
     * Create a hold with concurrency-safe stock reservation
     * Uses SELECT FOR UPDATE to prevent overselling
     */
    public function createHold(int $productId, int $qty): ?Hold
    {
        $lockKey = "product:{$productId}:lock";
        $lock = Cache::lock($lockKey, 10);
        
        try {
            if (!$lock->get()) {
                Log::warning('Failed to acquire lock for product', [
                    'product_id' => $productId,
                    'qty' => $qty,
                ]);
                return null;
            }

            return DB::transaction(function () use ($productId, $qty) {
                // Lock the product row for update
                $product = Product::where('id', $productId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Calculate available stock within transaction
                $activeHoldsQty = Hold::where('product_id', $productId)
                    ->where('status', 'active')
                    ->where('expires_at', '>', now())
                    ->sum('qty');

                $paidOrdersQty = DB::table('orders')
                    ->where('product_id', $productId)
                    ->where('status', 'paid')
                    ->sum('qty');

                $available = $product->total_stock - $activeHoldsQty - $paidOrdersQty;

                if ($available < $qty) {
                    Log::info('Insufficient stock for hold', [
                        'product_id' => $productId,
                        'requested_qty' => $qty,
                        'available_stock' => $available,
                    ]);
                    return null;
                }

                $hold = Hold::create([
                    'product_id' => $productId,
                    'qty' => $qty,
                    'expires_at' => now()->addMinutes(2),
                    'status' => 'active',
                ]);

                // Clear cache
                Cache::forget("product:{$productId}:available_stock");

                Log::info('Hold created successfully', [
                    'hold_id' => $hold->id,
                    'product_id' => $productId,
                    'qty' => $qty,
                ]);

                return $hold;
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Release stock from expired or cancelled holds
     */
    public function releaseStock(int $productId): void
    {
        Cache::forget("product:{$productId}:available_stock");
    }
}

