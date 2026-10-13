<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    protected $table = 'carts';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['user_id' => 'integer'];
    }

    public function items()
    {
        return $this->hasMany(CartItem::class);
    }
}
