<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $t->string('name');
            $t->string('slug')->unique();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('collections', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('size_guides', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->json('content');
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('size_guide_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('slug')->unique();
            $t->text('description')->nullable();
            $t->text('materials')->nullable();
            $t->text('care')->nullable();
            $t->string('status')->default('draft')->index();
            $t->boolean('returnable')->default(false);
            $t->unsignedInteger('weight_grams')->default(0);
            $t->string('seo_title')->nullable();
            $t->text('seo_description')->nullable();
            $t->timestamps();
        });
        Schema::create('collection_product', function (Blueprint $t) {
            $t->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->primary(['collection_id', 'product_id']);
        });
        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('sku')->unique();
            $t->string('color', 80);
            $t->string('size', 30);
            $t->unsignedBigInteger('price_cents');
            $t->unsignedBigInteger('compare_at_cents')->nullable();
            $t->unsignedInteger('stock')->default(0);
            $t->unsignedInteger('reserved')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['product_id', 'color', 'size']);
        });
        Schema::create('product_images', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->string('alt')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->uuid('request_key')->nullable()->unique();
            $t->integer('delta');
            $t->string('reason');
            $t->string('reference')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('collection_product');
        Schema::dropIfExists('products');
        Schema::dropIfExists('size_guides');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('categories');
    }
};
