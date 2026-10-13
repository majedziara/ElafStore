<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGateway extends Model
{
    protected $table = 'payment_gateways';

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'credentials' => 'encrypted:array'];
    }
}
