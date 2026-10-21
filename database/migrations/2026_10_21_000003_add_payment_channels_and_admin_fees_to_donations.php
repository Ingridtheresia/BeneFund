<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->string('payment_channel', 40)->nullable();
            // Preserve every existing order's charged amount, including active Snap tokens.
            $table->unsignedInteger('admin_fee')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropColumn(['payment_channel', 'admin_fee']);
        });
    }
};
