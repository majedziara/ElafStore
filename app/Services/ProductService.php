<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

final class ProductService
{
    public function save(array $data, ?Product $product, int $actor): Product
    {
        return DB::transaction(function () use ($data, $product, $actor) {
            $variants = $data['variants'];
            $collections = $data['collection_ids'];
            unset($data['variants'],$data['collection_ids']);
            if ($product) {
                $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                $product->update($data);
            } else {
                $product = Product::create($data);
            }
            $product->collections()->sync($collections);
            $retained = [];
            foreach ($variants as $row) {
                $id = $row['id'] ?? null;
                unset($row['id']);
                $stock = (int) $row['stock'];
                unset($row['stock']);
                if ($row['compare_at_cents'] ?? null) {
                    abort_if($row['compare_at_cents'] <= $row['price_cents'], 422, 'Compare-at price must exceed selling price.');
                }
                $variant = $id ? ProductVariant::where('product_id', $product->id)->whereKey($id)->lockForUpdate()->firstOrFail() : null;
                abort_if(ProductVariant::where('sku', $row['sku'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists(), 422, 'SKU already exists.');
                abort_if(ProductVariant::where('product_id', $product->id)->where('color', $row['color'])->where('size', $row['size'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists(), 422, 'Duplicate color/size combination.');
                if ($variant) {
                    if (OrderItem::where('product_variant_id', $variant->id)->exists()) {
                        foreach (['sku', 'color', 'size'] as $key) {
                            abort_if($row[$key] !== $variant->$key, 422, 'An ordered variant identity cannot be changed. Add a new variant instead.');
                        }
                    }
                    $variant->update($row);
                    if ($stock !== $variant->stock) {
                        app(InventoryService::class)->adjust($variant, $stock - $variant->stock, 'product_edit', $actor);
                    }
                } else {
                    $variant = $product->variants()->create($row + ['stock' => $stock]);
                    if ($stock) {
                        InventoryMovement::create(['product_variant_id' => $variant->id, 'actor_id' => $actor, 'delta' => $stock, 'reason' => 'initial_stock']);
                    }
                }
                $retained[] = $variant->id;
            }
            $product->variants()->whereNotIn('id', $retained)->update(['is_active' => false]);
            abort_if($product->status === 'published' && ! $product->variants()->where('is_active', true)->exists(), 422, 'Published products need an active variant.');
            app(AuditService::class)->record('product_saved', 'product', $product->id, ['variant_ids' => $retained]);

            return $product->fresh()->load(['variants', 'images', 'collections', 'sizeGuide']);
        }, 3);
    }
}
