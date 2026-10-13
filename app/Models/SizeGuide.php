<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SizeGuide extends Model
{
    protected $table = 'size_guides';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }
}
