<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::table('users', function (Blueprint $t) {
            $t->string('pending_email')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->boolean('marketing_opt_in')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['pending_email', 'is_active', 'marketing_opt_in']));
    }
};
