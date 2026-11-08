<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockMovementService
{
    public function create(array $data, User $user): StockMovement
    {
        return DB::transaction(function () use ($data, $user) {

            // 1. Lock product row
            $product = Product::query()
                ->lockForUpdate()
                ->findOrFail($data['product_id']);

            // 2. Stock IN / OUT
            if ($data['type'] === 'in') {

            $maxQuantity = 4294967295;

              if ($data['quantity'] > $maxQuantity - $product->quantity) {
        throw ValidationException::withMessages([
            'quantity' => 'Stock quantity exceeds the maximum allowed.',
        ]);
            }

              $product->quantity += $data['quantity'];

            } else {

            if ($product->quantity < $data['quantity']) {
                    throw ValidationException::withMessages([
                       'quantity' => 'Insufficient stock.',
                 ]);
            }

             $product->quantity -= $data['quantity'];
            }

            // 3. Update product quantity
            $product->save();

            // 4. Save movement history
            return StockMovement::create([
                'product_id' => $product->id,
                'user_id' => $user->id,
                'type' => $data['type'],
                'quantity' => $data['quantity'],
                'note' => $data['note'] ?? null,
            ]);

        }, 3);
    }
}