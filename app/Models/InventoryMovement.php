<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $table = 'inventory_movements';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
