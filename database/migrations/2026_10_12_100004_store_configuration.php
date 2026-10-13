<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->json('value')->nullable();
            $t->timestamps();
        });
        Schema::create('payment_gateways', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->boolean('enabled')->default(false);
            $t->string('environment')->default('sandbox');
            $t->text('credentials')->nullable();
            $t->timestamps();
        });
        Schema::create('shipping_methods', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->json('states')->nullable();
            $t->unsignedBigInteger('price_cents');
            $t->unsignedBigInteger('free_above_cents')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('tax_rates', function (Blueprint $t) {
            $t->id();
            $t->string('state', 2);
            $t->string('postal_code', 10)->nullable();
            $t->unsignedInteger('basis_points');
            $t->boolean('tax_shipping')->default(false);
            $t->timestamps();
            $t->index(['state', 'postal_code']);
        });
        Schema::create('coupons', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('type');
            $t->unsignedBigInteger('value');
            $t->unsignedBigInteger('minimum_cents')->default(0);
            $t->unsignedBigInteger('maximum_discount_cents')->nullable();
            $t->unsignedInteger('usage_limit')->nullable();
            $t->unsignedInteger('used')->default(0);
            $t->unsignedInteger('reserved')->default(0);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->json('product_ids')->nullable();
            $t->json('excluded_product_ids')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('content_pages', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('title');
            $t->json('blocks');
            $t->string('status')->default('draft');
            $t->string('seo_title')->nullable();
            $t->text('seo_description')->nullable();
            $t->timestamps();
        });
        Schema::create('faqs', function (Blueprint $t) {
            $t->id();
            $t->string('category');
            $t->string('question');
            $t->text('answer');
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('contact_messages', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('subject');
            $t->string('order_reference')->nullable();
            $t->text('message');
            $t->string('status')->default('new');
            $t->text('admin_note')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('content_pages');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('shipping_methods');
        Schema::dropIfExists('payment_gateways');
        Schema::dropIfExists('settings');
    }
};
