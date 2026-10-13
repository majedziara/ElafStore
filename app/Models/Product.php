<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'products';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['returnable' => 'boolean'];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function collections()
    {
        return $this->belongsToMany(Collection::class);
    }

    public function sizeGuide()
    {
        return $this->belongsTo(SizeGuide::class);
    }
}
