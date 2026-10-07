<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_boards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('system_key', 40)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'system_key']);
            $table->index(['user_id', 'name']);
        });

        Schema::create('wishlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wishlist_board_id')->constrained()->cascadeOnDelete();
            $table->string('product_key', 191);
            $table->char('variant_key', 64);
            $table->string('name', 191);
            $table->string('category', 120)->default('');
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('added_price', 15, 2)->default(0);
            $table->string('image', 500)->default('');
            $table->json('colours')->nullable();
            $table->json('sizes')->nullable();
            $table->string('colour', 120)->default('');
            $table->string('size', 40)->default('');
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->string('availability', 30)->default('In stock');
            $table->text('note')->nullable();
            $table->boolean('notify_price_drop')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'variant_key']);
            $table->index(['wishlist_board_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
        Schema::dropIfExists('wishlist_boards');
    }
};
