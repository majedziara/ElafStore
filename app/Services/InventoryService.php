<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\ProductVariant;

final class InventoryService
{
    public function lockVariants(array $ids)
    {
        return ProductVariant::whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    public function release(Order $order): void
    {
        if ($order->stock_status !== 'reserved') {
            return;
        }
        $items = $order->items()->get();
        $variants = $this->lockVariants($items->pluck('product_variant_id')->all());
        foreach ($items as $i) {
            $v = $variants[$i->product_variant_id];
            abort_if($v->reserved < $i->quantity, 409, 'Inventory reservation mismatch.');
            $v->decrement('reserved', $i->quantity);
        }
        $order->stock_status = 'released';
        if ($order->coupon_id && $order->coupon_status === 'reserved') {
            $c = Coupon::whereKey($order->coupon_id)->lockForUpdate()->first();
            if ($c) {
                abort_if($c->reserved < 1, 409, 'Coupon reservation mismatch.');
                $c->decrement('reserved');
            }
            $order->coupon_status = 'released';
        }
        $order->save();
    }

    public function capture(Order $order): void
    {
        if ($order->stock_status === 'deducted') {
            return;
        }
        $items = $order->items()->get();
        $variants = $this->lockVariants($items->pluck('product_variant_id')->all());
        foreach ($items as $i) {
            $v = $variants[$i->product_variant_id];
            $can = $order->stock_status === 'reserved' ? $v->stock >= $i->quantity && $v->reserved >= $i->quantity : $v->available >= $i->quantity;
            if (! $can) {
                $order->fulfillment_status = 'on_hold';
                $order->save();

                return;
            }
        }
        foreach ($items as $i) {
            $v = $variants[$i->product_variant_id];
            if ($order->stock_status === 'reserved') {
                $v->reserved -= $i->quantity;
            }
            $v->stock -= $i->quantity;
            $v->save();
            InventoryMovement::create(['product_variant_id' => $v->id, 'delta' => -$i->quantity, 'reason' => 'paid_order', 'reference' => $order->reference]);
        }
        $order->stock_status = 'deducted';
        if ($order->coupon_id && $order->coupon_status !== 'committed') {
            $c = Coupon::whereKey($order->coupon_id)->lockForUpdate()->first();
            if ($c) {
                if ($order->coupon_status === 'reserved') {
                    abort_if($c->reserved < 1, 409);
                    $c->reserved--;
                } $c->used++;
                $c->save();
            }
            $order->coupon_status = 'committed';
        }
        $order->save();
    }

    public function adjust(ProductVariant $variant, int $delta, string $reason, ?int $actor = null, ?string $requestKey = null): ProductVariant
    {
        $v = ProductVariant::whereKey($variant->id)->lockForUpdate()->firstOrFail();
        if ($requestKey) {
            $previous = InventoryMovement::where('request_key', $requestKey)->first();
            if ($previous) {
                abort_unless((int) $previous->product_variant_id === $v->id && (int) $previous->delta === $delta && $previous->reason === $reason, 409, 'Stock idempotency key mismatch.');

                return $v;
            }
        }
        abort_if($v->stock + $delta < $v->reserved, 422, 'Stock cannot fall below reserved quantity.');
        abort_if($v->stock + $delta > 1000000, 422, 'Stock limit exceeded.');
        $v->stock += $delta;
        $v->save();
        InventoryMovement::create(['product_variant_id' => $v->id, 'delta' => $delta, 'reason' => $reason, 'actor_id' => $actor, 'request_key' => $requestKey]);

        return $v;
    }
}
