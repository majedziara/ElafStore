<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $table = 'product_variants';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'price_cents' => 'integer', 'stock' => 'integer', 'reserved' => 'integer', 'product_id' => 'integer', 'compare_at_cents' => 'integer'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getAvailableAttribute(): int
    {
        return max(0, $this->stock - $this->reserved);
    }
}
