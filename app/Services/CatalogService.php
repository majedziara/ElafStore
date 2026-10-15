<?php

namespace App\Services;

use App\Models\Product;

final class CatalogService
{
    public function query(array $filters)
    {
        $q = Product::with(['category', 'collections', 'images', 'variants', 'sizeGuide'])->where('status', 'published');
        if (! empty($filters['ids'])) {
            $q->whereIn('products.id', $filters['ids']);
        }
        if (! empty($filters['q'])) {
            $q->where(function ($q) use ($filters) {
                $q->where('name', 'like', '%'.$filters['q'].'%')->orWhere('description', 'like', '%'.$filters['q'].'%');
            });
        }
        if (! empty($filters['category'])) {
            $q->whereHas('category', fn ($c) => $c->where('slug', $filters['category'])->where('is_active', true));
        }
        if (! empty($filters['collection'])) {
            $q->whereHas('collections', fn ($c) => $c->where('slug', $filters['collection'])->where('is_active', true));
        }
        $q->whereHas('variants', function ($v) use ($filters) {
            $v->where('is_active', true);
            foreach (['size', 'color'] as $key) {
                if (! empty($filters[$key])) {
                    $v->where($key, $filters[$key]);
                }
            }
            if (isset($filters['min_cents'])) {
                $v->where('price_cents', '>=', $filters['min_cents']);
            }
            if (isset($filters['max_cents'])) {
                $v->where('price_cents', '<=', $filters['max_cents']);
            }
            if (! empty($filters['in_stock'])) {
                $v->whereColumn('stock', '>', 'reserved');
            }
        });
        if (in_array($filters['sort'] ?? '', ['price_asc', 'price_desc'], true)) {
            $q->withMin(['variants as minimum_price' => fn ($v) => $v->where('is_active', true)], 'price_cents')
                ->orderBy('minimum_price', $filters['sort'] === 'price_asc' ? 'asc' : 'desc');
        } else {
            $q->latest('id');
        }

        return $q->orderBy('products.id');
    }
}
