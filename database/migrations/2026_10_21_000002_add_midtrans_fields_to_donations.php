<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->string('order_id', 64)->nullable()->unique();
            $table->text('snap_token')->nullable();
            $table->text('snap_redirect_url')->nullable();
            $table->string('gateway_transaction_id')->nullable()->unique();
            $table->string('gateway_status', 40)->nullable();
            $table->string('gateway_fraud_status', 40)->nullable();
            $table->string('payment_type', 40)->nullable();
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->index(['campaign_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'last_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropUnique(['order_id']);
            $table->dropUnique(['gateway_transaction_id']);
            $table->dropIndex(['campaign_id', 'status']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['status', 'last_checked_at']);
            $table->dropColumn([
                'order_id', 'snap_token', 'snap_redirect_url', 'gateway_transaction_id',
                'gateway_status', 'gateway_fraud_status', 'payment_type', 'refunded_amount',
                'expires_at', 'last_checked_at',
            ]);
        });
    }
};
