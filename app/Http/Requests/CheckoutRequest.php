<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['checkout_key' => $this->header('Idempotency-Key'), 'email' => strtolower(trim((string) $this->email))]);
    }

    public function rules(): array
    {
        return ['checkout_key' => 'required|uuid', 'email' => 'required|email:rfc|max:254',
            'shipping_address' => 'required|array:name,line1,line2,city,state,postal_code,country,phone',
            'billing_address' => 'required|array:name,line1,line2,city,state,postal_code,country,phone',
            'shipping_method_id' => 'required|integer|exists:shipping_methods,id', 'coupon_code' => 'nullable|string|max:60']
            + AddressRequest::addressRules('shipping_address.') + AddressRequest::addressRules('billing_address.');
    }
}
