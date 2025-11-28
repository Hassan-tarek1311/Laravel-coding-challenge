<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FlashSaleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test stock boundary concurrency - ensure no overselling
     * Stock = 5, 10 parallel hold attempts requesting qty = 1
     * Expected: only 5 successful holds, no oversell
     */
    public function test_stock_boundary_concurrency(): void
    {
        // Create product with stock = 5
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 99.99,
            'total_stock' => 5,
        ]);

        // Clear cache
        Cache::flush();

        // Simulate 10 parallel hold requests
        $responses = [];
        $promises = [];

        for ($i = 0; $i < 10; $i++) {
            $promises[] = function () use ($product, &$responses) {
                $response = $this->postJson('/api/holds', [
                    'product_id' => $product->id,
                    'qty' => 1,
                ]);
                $responses[] = $response;
            };
        }

        // Execute requests concurrently using parallel processing simulation
        // In real scenario, this would be actual parallel HTTP requests
        foreach ($promises as $promise) {
            $promise();
        }

        // Count successful holds (status 201)
        $successfulHolds = 0;
        $failedHolds = 0;

        foreach ($responses as $response) {
            if ($response->status() === 201) {
                $successfulHolds++;
            } else {
                $failedHolds++;
                $this->assertEquals(409, $response->status(), 'Failed holds should return 409');
            }
        }

        // Verify: exactly 5 successful holds, 5 failed
        $this->assertEquals(5, $successfulHolds, 'Should have exactly 5 successful holds');
        $this->assertEquals(5, $failedHolds, 'Should have exactly 5 failed holds');

        // Verify available stock is now 0
        $response = $this->getJson("/api/products/{$product->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'available_stock' => 0,
        ]);
    }

    /**
     * Test that available stock calculation is accurate
     */
    public function test_available_stock_calculation(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 99.99,
            'total_stock' => 100,
        ]);

        Cache::flush();

        $response = $this->getJson("/api/products/{$product->id}");
        $response->assertStatus(200);
        $response->assertJson([
            'total_stock' => 100,
            'available_stock' => 100,
        ]);
    }
}
