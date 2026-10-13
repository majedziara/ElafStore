<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LinkToken extends Model
{
    protected $table = 'link_tokens';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
