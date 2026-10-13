<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    protected $table = 'payment_attempts';

    protected $guarded = [];

    protected $hidden = ['gateway_snapshot'];

    protected function casts(): array
    {
        return ['gateway_snapshot' => 'encrypted:array', 'last_checked_at' => 'datetime', 'capture_requested_at' => 'datetime', 'order_id' => 'integer'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
