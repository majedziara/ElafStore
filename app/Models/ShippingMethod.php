<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{
    protected $table = 'shipping_methods';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['states' => 'array', 'is_active' => 'boolean', 'price_cents' => 'integer', 'free_above_cents' => 'integer'];
    }
}
