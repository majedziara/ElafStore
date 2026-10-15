<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropUnique(['provider_order_id']);
            $table->dropUnique(['capture_id']);
            $table->unique(['gateway', 'environment', 'provider_order_id'], 'payment_provider_order_unique');
            $table->unique(['gateway', 'environment', 'capture_id'], 'payment_provider_capture_unique');
        });
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropUnique(['provider_refund_id']);
            $table->unique(['payment_attempt_id', 'provider_refund_id'], 'refund_provider_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropUnique('refund_provider_attempt_unique');
            $table->unique('provider_refund_id');
        });
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropUnique('payment_provider_order_unique');
            $table->dropUnique('payment_provider_capture_unique');
            $table->unique('provider_order_id');
            $table->unique('capture_id');
        });
    }
};
