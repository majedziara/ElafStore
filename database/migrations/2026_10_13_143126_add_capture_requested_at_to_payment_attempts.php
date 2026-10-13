<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->timestamp('capture_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn('capture_requested_at');
        });
    }
};
