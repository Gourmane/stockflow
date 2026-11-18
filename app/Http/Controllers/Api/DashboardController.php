<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $stock = Product::query()
            ->toBase()
            ->selectRaw('COUNT(*) AS total_products')
            ->selectRaw('COALESCE(SUM(quantity), 0) AS total_units')
            ->selectRaw('COUNT(CASE WHEN quantity > 0 AND quantity <= min_stock THEN 1 END) AS low_stock_count')
            ->selectRaw('COUNT(CASE WHEN quantity = 0 THEN 1 END) AS out_of_stock_count')
            ->first();

        $recentMovements = StockMovement::query()
            ->with(['product:id,name,sku', 'user:id,name'])
            ->latest()
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'product_id', 'user_id', 'type', 'quantity', 'created_at'])
            ->setHidden(['product_id', 'user_id']);

        return response()->json([
            'total_products' => (int) $stock->total_products,
            'total_units' => (int) $stock->total_units,
            'low_stock_count' => (int) $stock->low_stock_count,
            'out_of_stock_count' => (int) $stock->out_of_stock_count,
            'recent_movements' => $recentMovements,
        ]);
    }
}
