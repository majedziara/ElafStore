<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $table = 'cart_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'product_variant_id' => 'integer'];
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
