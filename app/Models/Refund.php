<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $table = 'refunds';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['return_request_id' => 'integer', 'amount_cents' => 'integer', 'order_id' => 'integer', 'payment_attempt_id' => 'integer'];
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
