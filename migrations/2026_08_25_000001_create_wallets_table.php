<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('currency', 3)->default('NGN');
            $table->decimal('balance', 15, 2)->default(0);
            $table->decimal('store_credit_balance', 15, 2)->default(0);
            $table->decimal('purchase_cashback_balance', 15, 2)->default(0);
            $table->decimal('referral_available_balance', 15, 2)->default(0);
            $table->decimal('referral_pending_balance', 15, 2)->default(0);
            $table->decimal('pending_balance', 15, 2)->default(0);
            $table->decimal('reserved_balance', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
