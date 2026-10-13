<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('return_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('reference')->unique();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->string('type');
            $t->string('status')->default('submitted')->index();
            $t->text('notes')->nullable();
            $t->text('admin_note')->nullable();
            $t->json('photo_paths')->nullable();
            $t->boolean('exchange_reserved')->default(false);
            $t->boolean('exchange_deducted')->default(false);
            $t->boolean('restocked')->default(false);
            $t->timestamps();
        });
        Schema::create('return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $t->foreignId('replacement_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->string('reason');
            $t->timestamps();
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('return_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->string('carrier');
            $t->string('tracking_number');
            $t->string('tracking_url')->nullable();
            $t->timestamp('shipped_at');
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('return_items');
        Schema::dropIfExists('return_requests');
    }
};
