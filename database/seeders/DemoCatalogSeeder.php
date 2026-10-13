<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;

class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Demo data is local only.');
        $clothing = Category::firstOrCreate(['slug' => 'clothing'], ['name' => 'Clothing']);
        $intimates = Category::firstOrCreate(['slug' => 'intimates'], ['name' => 'Intimates']);
        foreach ([
            ['soft-rib-bralette', 'Soft Rib Bralette', $intimates->id, 'Dusty Blush', 3400],
            ['cotton-modal-brief', 'Cotton Modal Brief', $intimates->id, 'Dusty Blush', 1800],
            ['drape-satin-shirt', 'Drape Satin Shirt', $clothing->id, 'Ivory', 5800],
        ] as [$slug,$name,$category,$color,$price]) {
            $p = Product::firstOrCreate(['slug' => $slug], ['name' => $name, 'category_id' => $category, 'status' => 'published', 'returnable' => false]);
            foreach (['XS', 'S', 'M', 'L'] as $size) {
                ProductVariant::firstOrCreate(['sku' => strtoupper($slug.'-'.$size)],
                    ['product_id' => $p->id, 'color' => $color, 'size' => $size, 'price_cents' => $price, 'stock' => 20, 'is_active' => true]);
            }
        }
    }
}
