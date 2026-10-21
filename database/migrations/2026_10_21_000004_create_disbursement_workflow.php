<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Support both the original application and installations with Midtrans refunds.
        if (! Schema::hasColumn('donations', 'refunded_amount')) {
            Schema::table('donations', fn (Blueprint $table) => $table->unsignedBigInteger('refunded_amount')->default(0));
        }
        Schema::create('disbursements', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('request_key')->unique();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('expense_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('recipient_label', 150);
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('recipient_bank', 100);
            $table->text('recipient_account_name');
            $table->text('recipient_account_number');
            $table->unsignedBigInteger('amount');
            $table->string('status', 30)->default('requested');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('transfer_reference', 120)->nullable()->unique();
            $table->string('proof_path', 500)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'status']);
            $table->index(['recipient_user_id', 'created_at']);
            $table->index(['requested_by', 'created_at']);
        });
        Schema::create('disbursement_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disbursement_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 30);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursement_events');
        Schema::dropIfExists('disbursements');
        // The refund column may belong to an earlier payment installation; retain it.
    }
};
