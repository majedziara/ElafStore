<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['type' => 'required|in:return,exchange', 'notes' => 'nullable|string|max:3000',
            'items.*' => 'required|array:order_item_id,quantity,reason,replacement_variant_id', 'items' => 'required|array|min:1|max:50', 'items.*.order_item_id' => 'required|integer|distinct',
            'items.*.quantity' => 'required|integer|min:1|max:20', 'items.*.reason' => 'required|string|max:300',
            'items.*.replacement_variant_id' => 'nullable|integer|exists:product_variants,id'];
    }
}
