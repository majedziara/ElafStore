<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CheckoutService
{
    public function __construct(private CartService $carts, private InventoryService $stock, private SettingService $settings, private PricingService $pricing) {}

    public function quote(Request $r, array $data): array
    {
        abort_unless($this->settings->get('checkout_enabled', false), 503, 'Checkout is not open yet.');
        $cart = $this->carts->resolve($r);
        abort_unless($cart->status === 'open', 409, 'Cart already checked out.');
        $items = $cart->items()->get();
        abort_if($items->isEmpty(), 422, 'Your bag is empty.');
        $variants = ProductVariant::with('product')->whereIn('id', $items->pluck('product_variant_id'))->get()->keyBy('id');
        $subtotal = 0;
        foreach ($items as $i) {
            $v = $variants[$i->product_variant_id];
            abort_unless($v->is_active && $v->product->status === 'published' && $v->available >= $i->quantity, 422, 'An item is no longer available.');
            $subtotal += $v->price_cents * $i->quantity;
        }
        abort_if($subtotal > 100000000, 422, 'Order amount limit exceeded.');
        $price = $this->pricing->calculate($items, $variants, $subtotal, $data, false);

        return ['subtotal_cents' => $subtotal, 'discount_cents' => $price['discount'], 'shipping_cents' => $price['shipping'], 'tax_cents' => $price['tax'], 'total_cents' => $price['total'], 'currency' => 'USD'];
    }

    public function create(Request $r, array $data): Order
    {
        return DB::transaction(function () use ($r, $data) {
            $cart = $this->carts->resolve($r, true);
            $actor = $r->user('sanctum');
            if ($actor) {
                $data['email'] = $actor->email;
            }
            $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = Order::where('checkout_key', $data['checkout_key'])->first();
            if ($existing) {
                abort_unless($existing->cart_id === $cart->id && hash_equals($existing->request_hash, $fingerprint), 409, 'Idempotency key reused with different checkout data.');

                return $existing;
            }
            abort_unless($this->settings->get('checkout_enabled', false), 503, 'Checkout is not open yet.');
            abort_unless($cart->status === 'open', 409, 'Cart already checked out.');
            $items = $cart->items()->orderBy('product_variant_id')->get();
            abort_if($items->isEmpty(), 422, 'Your bag is empty.');
            $variants = $this->stock->lockVariants($items->pluck('product_variant_id')->all());
            $subtotal = 0;
            foreach ($items as $item) {
                $v = $variants[$item->product_variant_id];
                $v->load('product.images');
                abort_unless($v->is_active && $v->product->status === 'published' && $v->available >= $item->quantity, 422, 'An item is no longer available.');
                $subtotal += $v->price_cents * $item->quantity;
            }
            abort_if($subtotal > 100000000, 422, 'Order amount limit exceeded.');
            $price = $this->pricing->calculate($items, $variants, $subtotal, $data, true);
            ['coupon' => $coupon,'discount' => $discount,'eligible' => $eligible,'method' => $method,'shipping' => $shipping,'tax' => $tax,'total' => $total] = $price;
            $order = Order::create(['uuid' => (string) Str::uuid(), 'reference' => 'NV-'.Str::upper((string) Str::ulid()),
                'checkout_key' => $data['checkout_key'], 'request_hash' => $fingerprint, 'cart_id' => $cart->id, 'user_id' => $actor?->id, 'email' => $data['email'],
                'shipping_address' => $data['shipping_address'], 'billing_address' => $data['billing_address'], 'shipping_method' => $method->name,
                'subtotal_cents' => $subtotal, 'discount_cents' => $discount, 'shipping_cents' => $shipping, 'tax_cents' => $tax, 'total_cents' => $total,
                'coupon_id' => $coupon?->id, 'coupon_status' => $coupon ? 'reserved' : null, 'reservation_expires_at' => now()->addMinutes(config('store.reservation_minutes'))]);
            $remainingDiscount = $discount;
            $remainingEligible = array_sum($eligible);
            foreach ($items as $i) {
                $v = $variants[$i->product_variant_id];
                $p = $v->product;
                $allocation = 0;
                if (isset($eligible[$i->id])) {
                    $allocation = $remainingEligible === $eligible[$i->id] ? $remainingDiscount : intdiv($remainingDiscount * $eligible[$i->id], $remainingEligible);
                    $remainingDiscount -= $allocation;
                    $remainingEligible -= $eligible[$i->id];
                }
                OrderItem::create(['order_id' => $order->id, 'product_variant_id' => $v->id, 'product_name' => $p->name, 'sku' => $v->sku, 'color' => $v->color,
                    'size' => $v->size, 'image_path' => $p->images->first()?->path, 'returnable' => $p->returnable, 'quantity' => $i->quantity,
                    'unit_price_cents' => $v->price_cents, 'line_discount_cents' => $allocation]);
                $v->increment('reserved', $i->quantity);
            }
            if ($coupon) {
                $coupon->increment('reserved');
            }
            $cart->update(['status' => 'converted', 'user_id' => $actor?->id]);

            return $order->fresh();
        }, 3);
    }
}
