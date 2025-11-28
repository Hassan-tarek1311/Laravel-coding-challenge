<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private StockService $stockService
    ) {}

    public function show(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $availableStock = $this->stockService->getAvailableStock($id);

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'price' => $product->price,
            'total_stock' => $product->total_stock,
            'available_stock' => $availableStock,
        ]);
    }
}
