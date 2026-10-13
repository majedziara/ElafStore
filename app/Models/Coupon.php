<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $table = 'coupons';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['product_ids' => 'array', 'excluded_product_ids' => 'array', 'is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'value' => 'integer', 'minimum_cents' => 'integer', 'maximum_discount_cents' => 'integer', 'usage_limit' => 'integer', 'used' => 'integer', 'reserved' => 'integer'];
    }
}
