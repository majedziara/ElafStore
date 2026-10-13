<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderEvent extends Model
{
    protected $table = 'order_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['customer_visible' => 'boolean'];
    }
}
