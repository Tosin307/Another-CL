<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paystack_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('order_id', 100)->index();
            $table->string('reference', 120)->unique();
            $table->string('paid_order_key', 100)->nullable()->unique();
            $table->unsignedBigInteger('expected_amount');
            $table->char('currency', 3)->default('NGN');
            $table->string('environment', 10)->default('test');
            $table->string('status', 30)->default('initializing')->index();
            $table->string('provider_transaction_id', 32)->nullable()->unique();
            $table->string('wallet_reservation_reference', 120)->nullable()->index();
            $table->unsignedBigInteger('wallet_amount')->default(0);
            $table->string('channel', 30)->nullable();
            $table->string('card_brand', 30)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paystack_payments');
    }
};
