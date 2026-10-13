<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\ShippingMethod;
use App\Models\TaxRate;
use App\Support\Money;

final class PricingService
{
    public function __construct(private SettingService $settings) {}

    public function calculate($items, $variants, int $subtotal, array $data, bool $lockCoupon = false): array
    {
        $coupon = null;
        $discount = 0;
        $eligible = [];
        if (! empty($data['coupon_code'])) {
            $cq = Coupon::where('code', strtoupper(trim($data['coupon_code'])));
            if ($lockCoupon) {
                $cq->lockForUpdate();
            }
            $coupon = $cq->first();
            abort_unless($coupon && $coupon->is_active && (! $coupon->starts_at || $coupon->starts_at <= now()) &&
                (! $coupon->ends_at || $coupon->ends_at >= now()) && $subtotal >= $coupon->minimum_cents &&
                (! $coupon->usage_limit || $coupon->used + $coupon->reserved < $coupon->usage_limit), 422, 'Coupon is unavailable.');
            foreach ($items as $i) {
                $v = $variants[$i->product_variant_id];
                if ((! $coupon->product_ids || in_array($v->product_id, $coupon->product_ids)) && ! in_array($v->product_id, $coupon->excluded_product_ids ?? [])) {
                    $eligible[$i->id] = $i->quantity * $v->price_cents;
                }
            }
            $eligibleTotal = array_sum($eligible);
            abort_if($eligibleTotal === 0, 422, 'Coupon does not apply to these products.');
            $discount = $coupon->type === 'percentage' ? Money::percentage($eligibleTotal, $coupon->value) : min($coupon->value, $eligibleTotal);
            if ($coupon->maximum_discount_cents !== null) {
                $discount = min($discount, $coupon->maximum_discount_cents);
            }
            $discount = min($discount, $eligibleTotal);
        }
        $method = ShippingMethod::whereKey($data['shipping_method_id'])->where('is_active', true)->firstOrFail();
        $state = $data['shipping_address']['state'];
        abort_if($method->states && ! in_array($state, $method->states, true), 422, 'Shipping method unavailable for this address.');
        $shipping = $method->free_above_cents !== null && $subtotal - $discount >= $method->free_above_cents ? 0 : $method->price_cents;
        $tax = 0;
        $mode = $this->settings->get('tax_mode', 'unconfigured');
        abort_unless(in_array($mode, ['manual', 'none'], true), 503, 'Tax configuration is not ready.');
        if ($mode === 'manual') {
            $postal = $data['shipping_address']['postal_code'];
            $rate = TaxRate::where('state', $state)->where(function ($q) use ($postal) {
                $q->whereIn('postal_code', [$postal, substr($postal, 0, 5)])->orWhereNull('postal_code');
            })
                ->orderByRaw('CASE WHEN postal_code = ? THEN 0 WHEN postal_code = ? THEN 1 ELSE 2 END', [$postal, substr($postal, 0, 5)])->first();
            abort_unless($rate, 422, 'Tax rate is not configured for this destination.');
            $tax = Money::percentage($subtotal - $discount + ($rate->tax_shipping ? $shipping : 0), $rate->basis_points);
        }
        $total = $subtotal - $discount + $shipping + $tax;
        abort_if($total < 1,422,'A positive order total is required.');

        return compact('coupon','discount','eligible','method','shipping','tax','total');
    }
}
