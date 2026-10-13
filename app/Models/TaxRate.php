<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    protected $table = 'tax_rates';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tax_shipping' => 'boolean'];
    }
}
