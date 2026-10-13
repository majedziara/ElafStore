<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public static function addressRules(string $prefix = ''): array
    {
        return [
            $prefix.'name' => 'required|string|max:120', $prefix.'line1' => 'required|string|max:180',
            $prefix.'line2' => 'nullable|string|max:180', $prefix.'city' => 'required|string|max:100',
            $prefix.'state' => ['required', Rule::in(config('store.states'))],
            $prefix.'postal_code' => ['required', 'regex:/^\d{5}(-\d{4})?$/D'],
            $prefix.'country' => 'required|in:US', $prefix.'phone' => 'nullable|string|max:30',
        ];
    }

    public function rules(): array
    {
        return self::addressRules() + ['default_shipping' => 'sometimes|boolean', 'default_billing' => 'sometimes|boolean'];
    }
}
