<?php

namespace App\Http\Requests;

use App\Services\StockMovementService;
use Illuminate\Foundation\Http\FormRequest;

class StoreStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'type' => ['required', 'in:in,out'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.StockMovementService::MAX_QUANTITY],
            'note' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['sometimes', 'required', 'uuid'],
        ];
    }
}
