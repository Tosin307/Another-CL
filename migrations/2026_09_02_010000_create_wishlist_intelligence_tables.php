<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table): void {
            $table->string('product_id', 64)->nullable()->after('product_key');
            $table->string('product_code', 255)->nullable()->after('product_id');
            $table->string('variant_id', 64)->nullable()->after('variant_key');
            $table->string('variant_code', 255)->nullable()->after('variant_id');
            $table->json('selected_attributes')->nullable()->after('size');
            $table->char('currency', 3)->default('NGN')->after('added_price');
            $table->timestamp('alert_enabled_at')->nullable()->after('notify_price_drop');
            $table->timestamp('catalog_checked_at')->nullable()->after('alert_enabled_at');

            $table->index(['product_key', 'created_at']);
            $table->index(['product_code', 'created_at']);
            $table->index(['colour', 'size']);
            $table->index(['notify_price_drop', 'created_at']);
        });

        Schema::create('wishlist_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 191)->nullable()->unique();
            $table->string('event_type', 50);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('wishlist_board_id')->nullable();
            $table->unsignedBigInteger('wishlist_item_id')->nullable();
            $table->string('product_key', 191)->nullable();
            $table->string('product_id', 64)->nullable();
            $table->string('product_code', 255)->nullable();
            $table->char('variant_key', 64)->nullable();
            $table->string('variant_id', 64)->nullable();
            $table->string('variant_code', 255)->nullable();
            $table->string('colour', 120)->nullable();
            $table->string('size', 40)->nullable();
            $table->json('selected_attributes')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price', 15, 2)->nullable();
            $table->char('currency', 3)->default('NGN');
            $table->string('order_id', 100)->nullable();
            $table->string('order_product_id', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['user_id', 'event_type', 'occurred_at']);
            $table->index(['product_key', 'event_type', 'occurred_at']);
            $table->index(['variant_key', 'event_type', 'occurred_at']);
            $table->index(['wishlist_board_id', 'occurred_at']);
            $table->index(['wishlist_item_id', 'occurred_at']);
            $table->index(['order_id', 'event_type']);
            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_events');

        Schema::table('wishlist_items', function (Blueprint $table): void {
            $table->dropIndex(['product_key', 'created_at']);
            $table->dropIndex(['product_code', 'created_at']);
            $table->dropIndex(['colour', 'size']);
            $table->dropIndex(['notify_price_drop', 'created_at']);
            $table->dropColumn([
                'product_id',
                'product_code',
                'variant_id',
                'variant_code',
                'selected_attributes',
                'currency',
                'alert_enabled_at',
                'catalog_checked_at',
            ]);
        });
    }
};
