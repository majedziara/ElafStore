<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['variant_id' => 'required|integer|exists:product_variants,id', 'quantity' => 'required|integer|min:0|max:20'];
    }
}
