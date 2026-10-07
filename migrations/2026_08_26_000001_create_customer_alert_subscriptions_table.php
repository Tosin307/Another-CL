<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_alert_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 40);
            $table->string('product_key', 180)->nullable();
            $table->string('product_name', 180);
            $table->string('colour', 120)->nullable();
            $table->string('size', 20)->nullable();
            $table->string('first_name', 120)->nullable();
            $table->string('email', 180);
            $table->string('country', 120)->nullable();
            $table->string('country_code', 12)->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['email', 'kind', 'status']);
            $table->index(['product_key', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_alert_subscriptions');
    }
};
