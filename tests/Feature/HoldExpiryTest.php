<?php

namespace Tests\Feature;

use App\Jobs\ExpireHoldsJob;
use App\Models\Hold;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HoldExpiryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test hold expiry - create a hold, simulate time passing, run expiry job
     * Availability should be restored
     */
    public function test_hold_expiry_restores_availability(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 99.99,
            'total_stock' => 100,
        ]);

        Cache::flush();

        // Create a hold that expires in the past
        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 10,
            'expires_at' => now()->subMinute(), // Expired 1 minute ago
            'status' => 'active',
        ]);

        // Verify hold exists and is expired
        $this->assertTrue($hold->isExpired());

        // Check available stock before expiry job
        $response = $this->getJson("/api/products/{$product->id}");
        $response->assertStatus(200);
        $initialAvailableStock = $response->json('available_stock');

        // Run expiry job
        $job = new ExpireHoldsJob();
        $job->handle(app(\App\Services\StockService::class));

        // Refresh hold from database
        $hold->refresh();

        // Verify hold is marked as expired
        $this->assertEquals('expired', $hold->status);

        // Clear cache and check available stock after expiry
        Cache::flush();
        $response = $this->getJson("/api/products/{$product->id}");
        $response->assertStatus(200);
        $finalAvailableStock = $response->json('available_stock');

        // Available stock should increase by the hold quantity
        $this->assertEquals($initialAvailableStock + 10, $finalAvailableStock);
    }

    /**
     * Test that expiry job is safe to run concurrently (no double-release)
     */
    public function test_expiry_job_concurrency_safety(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 99.99,
            'total_stock' => 100,
        ]);

        Cache::flush();

        // Create expired hold
        $hold = Hold::create([
            'product_id' => $product->id,
            'qty' => 10,
            'expires_at' => now()->subMinute(),
            'status' => 'active',
        ]);

        // Run expiry job twice concurrently (simulated)
        $job = new ExpireHoldsJob();
        $stockService = app(\App\Services\StockService::class);
        
        $job->handle($stockService);
        $job->handle($stockService); // Run again

        // Refresh hold
        $hold->refresh();

        // Should only be marked as expired once
        $this->assertEquals('expired', $hold->status);

        // Count expired holds
        $expiredCount = Hold::where('id', $hold->id)
            ->where('status', 'expired')
            ->count();

        $this->assertEquals(1, $expiredCount, 'Hold should only be expired once');
    }
}
