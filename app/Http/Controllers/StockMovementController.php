<?php

namespace App\Http\Controllers;

use App\Models\StockMovement;
use App\Http\Requests\StoreStockMovementRequest;
use App\Services\StockMovementService;
use Illuminate\Http\JsonResponse;

class StockMovementController extends Controller
{
    // GET - History (later)
    public function index(): JsonResponse
    {
     $movements = StockMovement::query()
        ->with([
                'product:id,name,sku',
                'user:id,name',
           ])
            ->latest()
            ->paginate(10);

     return response()->json($movements, 200);
    }

    // POST - Stock IN / OUT
    public function store(
        StoreStockMovementRequest $request,
        StockMovementService $service
    ): JsonResponse {

        $movement = $service->create(
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'message' => 'Stock movement created successfully',
            'data' => $movement,
        ], 201);
    }
}