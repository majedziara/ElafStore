<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug, 'description' => $this->description,
            'materials' => $this->materials, 'care' => $this->care, 'returnable' => $this->returnable,
            'category' => $this->category?->only(['id', 'name', 'slug']),
            'collections' => $this->collections->map->only(['id', 'name', 'slug']),
            'size_guide' => $this->sizeGuide?->content, 'seo_title' => $this->seo_title, 'seo_description' => $this->seo_description,
            'images' => $this->images->map(fn ($i) => ['id' => $i->id, 'url' => Storage::disk('public')->url($i->path), 'alt' => $i->alt, 'sort_order' => $i->sort_order]),
            'variants' => $this->variants->where('is_active', true)->values()->map(fn ($v) => [
                'id' => $v->id, 'sku' => $v->sku, 'color' => $v->color, 'size' => $v->size, 'price_cents' => $v->price_cents,
                'compare_at_cents' => $v->compare_at_cents, 'available' => $v->available]), 'currency' => 'USD'];
    }
}
