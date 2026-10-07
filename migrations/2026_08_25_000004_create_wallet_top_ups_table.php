<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_top_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30)->default('paystack');
            $table->string('reference', 120)->unique();
            $table->decimal('amount', 15, 2);
            $table->decimal('bonus_amount', 15, 2)->default(0);
            $table->decimal('credit_amount', 15, 2);
            $table->string('status', 30)->default('pending');
            $table->string('provider_reference', 120)->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_top_ups');
    }
};
