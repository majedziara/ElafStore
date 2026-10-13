<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnRequest extends Model
{
    protected $table = 'return_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['photo_paths' => 'array', 'exchange_reserved' => 'boolean', 'exchange_deducted' => 'boolean', 'restocked' => 'boolean', 'order_id' => 'integer'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function items()
    {
        return $this->hasMany(ReturnItem::class);
    }
}
