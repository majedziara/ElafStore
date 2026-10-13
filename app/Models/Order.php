<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['shipping_address' => 'array', 'billing_address' => 'array', 'reservation_expires_at' => 'datetime', 'paid_at' => 'datetime', 'shipped_at' => 'datetime', 'delivered_at' => 'datetime', 'canceled_at' => 'datetime', 'subtotal_cents' => 'integer', 'discount_cents' => 'integer', 'shipping_cents' => 'integer', 'tax_cents' => 'integer', 'total_cents' => 'integer', 'refunded_cents' => 'integer', 'cart_id' => 'integer', 'user_id' => 'integer', 'coupon_id' => 'integer'];
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function shipments()
    {
        return $this->hasMany(Shipment::class);
    }

    public function returns()
    {
        return $this->hasMany(ReturnRequest::class);
    }

    public function events()
    {
        return $this->hasMany(OrderEvent::class)->latest('id');
    }
}
