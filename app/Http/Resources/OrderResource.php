<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['uuid' => $this->uuid, 'reference' => $this->reference, 'created_at' => $this->created_at, 'email' => $this->email,
            'payment_status' => $this->payment_status, 'fulfillment_status' => $this->fulfillment_status, 'currency' => $this->currency,
            'subtotal_cents' => $this->subtotal_cents, 'discount_cents' => $this->discount_cents, 'shipping_cents' => $this->shipping_cents,
            'tax_cents' => $this->tax_cents, 'total_cents' => $this->total_cents, 'refunded_cents' => $this->refunded_cents,
            'shipping_method' => $this->shipping_method, 'shipping_address' => $this->shipping_address, 'billing_address' => $this->billing_address,
            'reservation_expires_at' => $this->reservation_expires_at, 'paid_at' => $this->paid_at, 'shipped_at' => $this->shipped_at, 'delivered_at' => $this->delivered_at,
            'items' => $this->items->map(fn ($i) => $i->only(['id', 'product_name', 'sku', 'color', 'size', 'quantity', 'unit_price_cents', 'line_discount_cents', 'returnable']) +
                ['image_url' => $i->image_path ? Storage::disk('public')->url($i->image_path) : null]),
            'shipments' => $this->shipments->map->only(['id', 'return_request_id', 'carrier', 'tracking_number', 'tracking_url', 'shipped_at', 'delivered_at']),
            'refunds' => $this->refunds->where('status', '!=', 'merged')->values()->map->only(['amount_cents', 'status', 'created_at', 'return_request_id']),
            'payments' => $this->payments->map->only(['uuid', 'gateway', 'status', 'created_at']),
            'events' => $this->events->where('customer_visible', true)->values()->map->only(['type', 'message', 'created_at']),
            'receipt_available' => (bool) $this->paid_at];
    }
}
