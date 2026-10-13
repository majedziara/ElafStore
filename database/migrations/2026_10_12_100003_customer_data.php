<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('line1');
            $t->string('line2')->nullable();
            $t->string('city');
            $t->string('state', 2);
            $t->string('postal_code', 10);
            $t->string('country', 2)->default('US');
            $t->string('phone', 30)->nullable();
            $t->boolean('default_shipping')->default(false);
            $t->boolean('default_billing')->default(false);
            $t->timestamps();
        });
        Schema::create('wishlists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'product_id']);
        });
        Schema::create('link_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('token_hash', 64)->unique();
            $t->string('purpose')->index();
            $t->unsignedBigInteger('target_id')->index();
            $t->json('payload')->nullable();
            $t->timestamp('expires_at')->index();
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });
        Schema::create('carts', function (Blueprint $t) {
            $t->id();
            $t->string('token_hash', 64)->unique();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status')->default('open')->index();
            $t->timestamps();
        });
        Schema::create('cart_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->timestamps();
            $t->unique(['cart_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('link_tokens');
        Schema::dropIfExists('wishlists');
        Schema::dropIfExists('addresses');
    }
};
