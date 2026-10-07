<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_admin_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_id', 64);
            $table->string('product_code', 255);
            $table->string('action', 60);
            $table->string('section', 40);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        Schema::create('product_stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_id', 64);
            $table->string('product_code', 255);
            $table->string('stock_type', 32)->default('default');
            $table->decimal('quantity_before', 12, 2)->nullable();
            $table->decimal('quantity_after', 12, 2)->nullable();
            $table->decimal('quantity_delta', 12, 2)->nullable();
            $table->string('reason', 50);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
            $table->index(['reason', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_adjustments');
        Schema::dropIfExists('product_admin_activities');
    }
};
