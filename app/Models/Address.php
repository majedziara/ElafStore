<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $table = 'addresses';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['default_shipping' => 'boolean', 'default_billing' => 'boolean', 'user_id' => 'integer'];
    }
}
