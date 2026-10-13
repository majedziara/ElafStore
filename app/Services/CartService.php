<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class CartService
{
    public function create(Request $r): array
    {
        $user = $r->user('sanctum');
        abort_if($user && ! $user->is_active, 403, 'Account disabled.');
        $raw = bin2hex(random_bytes(32));
        $cart = Cart::create(['token_hash' => hash('sha256', $raw), 'user_id' => $user?->id]);

        return ['cart' => $cart->fresh(), 'cart_token' => $raw];
    }

    public function resolve(Request $r, bool $lock = false): Cart
    {
        $raw = (string) $r->header('X-Cart-Token');
        abort_unless(strlen($raw) === 64, 401, 'Cart token required.');
        $query = Cart::where('token_hash', hash('sha256', $raw));
        if ($lock) {
            $query->lockForUpdate();
        }
        $cart = $query->firstOrFail();
        $user = $r->user('sanctum');
        abort_if($user && ! $user->is_active, 403, 'Account disabled.');
        if ($cart->user_id) {
            abort_unless($cart->user_id === $user?->id, 404);
        }

        return $cart;
    }

    public function set(Request $r, array $data): Cart
    {
        $data['quantity'] = (int) $data['quantity'];

        return DB::transaction(function () use ($r, $data) {
            $cart = $this->resolve($r, true);
            abort_unless($cart->status === 'open', 409, 'Cart already checked out.');
            $v = ProductVariant::with('product')->whereKey($data['variant_id'])->lockForUpdate()->firstOrFail();
            if ($data['quantity'] === 0) {
                CartItem::where('cart_id', $cart->id)->where('product_variant_id', $v->id)->delete();
            } else {
                abort_unless($v->is_active && $v->product->status === 'published', 422, 'Product unavailable.');
                abort_if($data['quantity'] > $v->available, 422, 'Requested quantity is unavailable.');
                abort_if($cart->items()->count() >= 50 && ! $cart->items()->where('product_variant_id', $v->id)->exists(), 422, 'Cart item limit reached.');
                CartItem::updateOrCreate(['cart_id' => $cart->id, 'product_variant_id' => $v->id], ['quantity' => $data['quantity']]);
            }

            return $cart;
        }, 3);
    }

    public function view(Cart $cart): array
    {
        $items = $cart->items()->with('variant.product.images')->get();
        $subtotal = 0;
        $lines = $items->map(function ($i) use (&$subtotal) {
            $v = $i->variant;
            $p = $v->product;
            $line = $i->quantity * $v->price_cents;
            $subtotal += $line;

            return ['variant_id' => $v->id, 'product_id' => $p->id, 'name' => $p->name, 'color' => $v->color, 'size' => $v->size,
                'quantity' => $i->quantity, 'unit_price_cents' => $v->price_cents, 'line_total_cents' => $line, 'available' => $v->available,
                'purchasable' => $v->is_active && $p->status === 'published' && $v->available >= $i->quantity,
                'image_url' => $p->images->first()?->path ? Storage::disk('public')->url($p->images->first()->path) : null];
        });

        return ['id' => $cart->id, 'status' => $cart->status, 'items' => $lines, 'subtotal_cents' => $subtotal, 'currency' => 'USD'];
    }
}
