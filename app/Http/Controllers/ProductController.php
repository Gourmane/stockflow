<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $maxPerPage = 100;
        $maxPage = intdiv(PHP_INT_MAX, $maxPerPage);
        $pagination = Validator::make($request->query(), [
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.$maxPage],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.$maxPerPage],
        ])->validate();

        $products = Product::with('category')
            ->orderBy('id')
            ->paginate((int) ($pagination['per_page'] ?? 10), page: (int) ($pagination['page'] ?? 1))
            ->appends($pagination);

        return response()->json($products);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        $product->refresh()->load('category');

        return response()->json([
            'data' => $product,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product): JsonResponse
    {
        $product->load('category');

        return response()->json([
            'data' => $product,
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product->update($request->validated());

        $product->load('category');

        return response()->json([
            'message' => 'Product updated successfully',
            'data' => $product,
        ], 200);
    }
}
