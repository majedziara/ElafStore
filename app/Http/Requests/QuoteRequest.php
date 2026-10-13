<?php

namespace App\Http\Requests;

class QuoteRequest extends CheckoutRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['checkout_key']);

        return $rules;
    }
}
