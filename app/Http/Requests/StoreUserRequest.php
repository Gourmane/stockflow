<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'password' => [
                'bail',
                'required',
                'string',
                'min:12',
                'confirmed',
                function (string $attribute, string $value, Closure $fail): void {
                    if (str_contains($value, "\0")) {
                        $fail('The password must not contain null bytes.');
                    }

                    if (config('hashing.driver') === 'bcrypt') {
                        $limit = min(72, (int) (config('hashing.bcrypt.limit') ?: 72));

                        if (strlen($value) > $limit) {
                            $fail("The password must not exceed {$limit} bytes.");
                        }
                    }
                },
            ],

            'role' => ['sometimes', 'required', 'string', Rule::in(['admin', 'employee'])],

            'is_active' => ['missing'],
        ];
    }
}
