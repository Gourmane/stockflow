<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class StockMovementService
{
    public const int MAX_QUANTITY = 4_294_967_295;

    /** @param array{product_id: int|string, type: 'in'|'out', quantity: int|string, note?: string|null, idempotency_key?: string} $data */
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

        $idempotencyKey = strtolower($data['idempotency_key'] ?? (string) Str::uuid());
        $note = $data['note'] ?? null;

        return DB::transaction(function () use ($data, $user, $quantity, $idempotencyKey, $note) {
            $product = Product::query()
                ->lockForUpdate()
                ->findOrFail($data['product_id']);

            // Claim the key before checking current stock, which may have changed since the first request.
            $movement = StockMovement::query()->createOrFirst([
                'user_id' => $user->id,
                'idempotency_key' => $idempotencyKey,
            ], [
                'product_id' => $product->id,
                'type' => $data['type'],
                'quantity' => $quantity,
                'note' => $note,
            ]);

            if (! $movement->wasRecentlyCreated) {
                if ($movement->product_id !== $product->id
                    || $movement->type !== $data['type']
                    || $movement->quantity !== $quantity
                    || $movement->note !== $note) {
                    throw new ConflictHttpException('This idempotency key was already used for a different stock movement.');
                }

                return $movement;
            }

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

            return $movement;
        }, 3);
    }
}
