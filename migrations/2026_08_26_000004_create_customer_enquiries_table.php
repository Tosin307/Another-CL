<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_enquiries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('product_key')->nullable();
            $table->string('product_name');
            $table->string('colour')->nullable();
            $table->string('size')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('country');
            $table->string('country_code', 12)->nullable();
            $table->string('phone');
            $table->string('email');
            $table->text('message');
            $table->string('status', 32)->default('new');
            $table->timestamps();
            $table->index(['email', 'status']);
            $table->index(['product_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_enquiries');
    }
};
