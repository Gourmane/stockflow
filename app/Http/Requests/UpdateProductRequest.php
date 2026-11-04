<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:categories,id',
            ],

            'sku' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->ignore($this->route('product')),
            ],

            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'brand' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'unit_price' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
            ],

            'quantity' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
            ],

            'min_stock' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
            ],

            'is_active' => [
                'sometimes',
                'required',
                'boolean',
            ],

            'image_url' => [
                'sometimes',
                'nullable',
                'url',
            ],
        ];
    }
}
