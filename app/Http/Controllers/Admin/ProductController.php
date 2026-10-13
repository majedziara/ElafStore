<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\InventoryService;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $r)
    {
        $f = $r->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:draft,published,archived', 'category_id' => 'nullable|integer',
            'stock' => 'nullable|in:low,out,available', 'per_page' => 'nullable|integer|min:1|max:100']);
        $q = Product::with(['category', 'variants', 'images', 'collections']);
        if (! empty($f['q'])) {
            $q->where(fn ($q) => $q->where('name', 'like', '%'.$f['q'].'%')->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', '%'.$f['q'].'%')));
        }
        foreach (['status', 'category_id'] as $key) {
            if (isset($f[$key])) {
                $q->where($key, $f[$key]);
            }
        }
        if (isset($f['stock'])) {
            $q->whereHas('variants', function ($v) use ($f) {
                $v->where('is_active', true);
                if ($f['stock'] === 'out') {
                    $v->whereColumn('stock', '<=', 'reserved');
                } elseif ($f['stock'] === 'available') {
                    $v->whereColumn('stock', '>', 'reserved');
                } else {
                    $v->whereRaw('stock - reserved BETWEEN 1 AND 3');
                }
            });
        }

        return $q->latest('id')->paginate($f['per_page'] ?? 25);
    }

    public function show(Product $product)
    {
        return ['data' => $product->load(['variants', 'images', 'collections', 'sizeGuide'])];
    }

    public function save(ProductRequest $r, ProductService $s, ?Product $product = null)
    {
        return ['data' => $s->save($r->validated(), $product, $r->user()->id)];
    }

    public function archive(Product $product)
    {
        $product->update(['status' => 'archived']);

        return response()->noContent();
    }

    public function adjust(Request $r, ProductVariant $variant, InventoryService $s)
    {
        $d = $r->validate(['request_key' => 'required|uuid', 'delta' => 'required|integer|between:-1000000,1000000', 'reason' => 'required|string|max:180']);

        return ['data' => DB::transaction(fn () => $s->adjust($variant, (int) $d['delta'], $d['reason'], $r->user()->id, $d['request_key']), 3)];
    }

    public function image(Request $r, Product $product)
    {
        $d = $r->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:8192', 'alt' => 'nullable|string|max:180', 'sort_order' => 'nullable|integer|min:0|max:1000']);
        $path = $r->file('image')->store('products', 'public');
        try {
            $image = $product->images()->create(['path' => $path, 'alt' => $d['alt'] ?? null, 'sort_order' => $d['sort_order'] ?? 0]);
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }

        return ['data' => $image, 'url' => Storage::disk('public')->url($path)];
    }

    public function updateImage(Request $r, Product $product, ProductImage $image)
    {
        abort_unless($image->product_id === $product->id, 404);
        $image->update($r->validate(['alt' => 'nullable|string|max:180', 'sort_order' => 'required|integer|min:0|max:1000']));

        return ['data' => $image];
    }

    public function deleteImage(Product $product, ProductImage $image)
    {
        abort_unless($image->product_id === $product->id, 404);
        // Old order snapshots can still reference this file; retain bytes rather than breaking receipts.
        $image->delete();

        return response()->noContent();
    }
}
