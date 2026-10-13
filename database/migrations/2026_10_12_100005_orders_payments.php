<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('reference')->unique();
            $t->uuid('checkout_key')->unique();
            $t->string('request_hash', 64);
            $t->foreignId('cart_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $t->string('email');
            $t->json('shipping_address');
            $t->json('billing_address');
            $t->string('shipping_method');
            $t->string('currency', 3)->default('USD');
            $t->unsignedBigInteger('subtotal_cents');
            $t->unsignedBigInteger('discount_cents')->default(0);
            $t->unsignedBigInteger('shipping_cents');
            $t->unsignedBigInteger('tax_cents');
            $t->unsignedBigInteger('total_cents');
            $t->unsignedBigInteger('refunded_cents')->default(0);
            $t->string('payment_status')->default('pending')->index();
            $t->string('fulfillment_status')->default('unfulfilled')->index();
            $t->string('stock_status')->default('reserved');
            $t->string('coupon_status')->nullable();
            $t->timestamp('reservation_expires_at')->index();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('shipped_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('canceled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $t->string('product_name');
            $t->string('sku');
            $t->string('color');
            $t->string('size');
            $t->string('image_path')->nullable();
            $t->boolean('returnable')->default(false);
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_price_cents');
            $t->unsignedBigInteger('line_discount_cents')->default(0);
            $t->timestamps();
        });
        Schema::create('payment_attempts', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->string('gateway')->default('paypal');
            $t->string('environment');
            $t->text('gateway_snapshot');
            $t->string('status')->default('creating')->index();
            $t->string('provider_order_id')->nullable()->unique();
            $t->string('capture_id')->nullable()->unique();
            $t->text('approval_url')->nullable();
            $t->string('last_error')->nullable();
            $t->timestamp('last_checked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('refunds', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_key')->unique();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('payment_attempt_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('return_request_id')->nullable()->index();
            $t->string('provider_refund_id')->nullable()->unique();
            $t->unsignedBigInteger('amount_cents');
            $t->string('status')->default('pending')->index();
            $t->string('reason');
            $t->timestamps();
        });
        Schema::create('webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('provider');
            $t->string('environment');
            $t->string('provider_event_id');
            $t->string('type');
            $t->json('payload');
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'environment', 'provider_event_id']);
        });
        Schema::create('order_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('type');
            $t->text('message');
            $t->boolean('customer_visible')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
