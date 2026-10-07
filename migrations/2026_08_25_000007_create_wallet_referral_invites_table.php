<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_referral_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('coupon', 80)->index();
            $table->string('friend_name', 120)->nullable();
            $table->string('friend_email', 180)->nullable();
            $table->string('friend_whatsapp', 50)->nullable();
            $table->string('friend_discount', 80);
            $table->string('referrer_reward', 100);
            $table->string('status', 30)->default('invited');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_referral_invites');
    }
};
