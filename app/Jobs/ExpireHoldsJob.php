<?php

namespace App\Jobs;

use App\Models\Hold;
use App\Services\StockService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireHoldsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     */
    public function handle(StockService $stockService): void
    {
        $expiredCount = 0;
        $processedProductIds = [];

        // Process expired holds in batches to avoid memory issues
        Hold::where('status', 'active')
            ->where('expires_at', '<=', now())
            ->chunkById(100, function ($holds) use (&$expiredCount, &$processedProductIds, $stockService) {
                foreach ($holds as $hold) {
                    DB::transaction(function () use ($hold, &$expiredCount, &$processedProductIds, $stockService) {
                        // Re-check with lock to prevent double-processing
                        $lockedHold = Hold::lockForUpdate()->find($hold->id);
                        
                        if (!$lockedHold || $lockedHold->status !== 'active') {
                            return; // Already processed
                        }

                        if ($lockedHold->isExpired()) {
                            $lockedHold->markAsExpired();
                            $expiredCount++;
                            $processedProductIds[$lockedHold->product_id] = true;
                            
                            Log::info('Hold expired', [
                                'hold_id' => $lockedHold->id,
                                'product_id' => $lockedHold->product_id,
                                'qty' => $lockedHold->qty,
                            ]);
                        }
                    });
                }
            });

        // Clear cache for affected products
        foreach (array_keys($processedProductIds) as $productId) {
            $stockService->releaseStock($productId);
        }

        if ($expiredCount > 0) {
            Log::info('Expired holds processed', [
                'count' => $expiredCount,
                'products_affected' => count($processedProductIds),
            ]);
        }
    }
}
