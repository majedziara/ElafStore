<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentPage extends Model
{
    protected $table = 'content_pages';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['blocks' => 'array'];
    }
}
