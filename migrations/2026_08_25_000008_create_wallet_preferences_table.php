<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('offer_interest', 100)->nullable();
            $table->boolean('auto_recharge_enabled')->default(false);
            $table->decimal('auto_recharge_amount', 15, 2)->nullable();
            $table->string('auto_recharge_frequency', 40)->nullable();
            $table->string('auto_recharge_card_label', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_preferences');
    }
};
