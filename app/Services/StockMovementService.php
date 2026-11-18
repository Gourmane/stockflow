<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockMovementService
{
    public const int MAX_QUANTITY = 4_294_967_295;

    /** @param array{product_id: int|string, type: 'in'|'out', quantity: int|string, note?: string|null} $data */
    public function create(array $data, User $user): StockMovement
    {
        $quantity = $data['quantity'];
        if (is_string($quantity)) {
            $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
        }

        if (! is_int($quantity) || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw ValidationException::withMessages([
                'quantity' => 'The quantity must be a positive integer within the allowed range.',
            ]);
        }

        return DB::transaction(function () use ($data, $user, $quantity) {
            $product = Product::query()
                ->lockForUpdate()
                ->findOrFail($data['product_id']);

            if (! $product->is_active) {
                throw ValidationException::withMessages([
                    'product_id' => 'This product is inactive.',
                ]);
            }

            if ($data['type'] === 'in') {
                if ($quantity > self::MAX_QUANTITY - $product->quantity) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Stock quantity exceeds the maximum allowed.',
                    ]);
                }

                $product->quantity += $quantity;
            } else {
                if ($quantity > $product->quantity) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Insufficient stock.',
                    ]);
                }

                $product->quantity -= $quantity;
            }

            $product->save();

            return StockMovement::create([
                'product_id' => $product->id,
                'user_id' => $user->id,
                'type' => $data['type'],
                'quantity' => $quantity,
                'note' => $data['note'] ?? null,
            ]);
        }, 3);
    }
}
