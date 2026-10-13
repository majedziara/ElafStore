<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $table = 'order_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['returnable' => 'boolean', 'unit_price_cents' => 'integer', 'line_discount_cents' => 'integer', 'quantity' => 'integer', 'product_variant_id' => 'integer'];
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
