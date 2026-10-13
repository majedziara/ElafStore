<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    protected $table = 'contact_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
