<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnItem extends Model
{
    protected $table = 'return_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function returnRequest()
    {
        return $this->belongsTo(ReturnRequest::class);
    }
}
