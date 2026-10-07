<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_alert_subscriptions', function (Blueprint $table): void {
            $table->foreignId('wishlist_item_id')->nullable()->after('user_id')->constrained('wishlist_items')->nullOnDelete();
            $table->string('product_id', 64)->nullable()->after('product_key');
            $table->string('product_code', 255)->nullable()->after('product_id');
            $table->char('variant_key', 64)->nullable()->after('product_code');
            $table->string('variant_id', 64)->nullable()->after('variant_key');
            $table->string('variant_code', 255)->nullable()->after('variant_id');
            $table->json('selected_attributes')->nullable()->after('size');
            $table->char('currency', 3)->default('NGN')->after('selected_attributes');
            $table->decimal('subscription_price', 15, 2)->nullable()->after('currency');
            $table->decimal('last_observed_price', 15, 2)->nullable()->after('subscription_price');
            $table->decimal('last_notified_price', 15, 2)->nullable()->after('last_observed_price');
            $table->char('active_key', 64)->nullable()->unique()->after('last_notified_price');
            $table->char('unsubscribe_token', 64)->nullable()->unique()->after('active_key');
            $table->timestamp('subscribed_at')->nullable()->after('status');
            $table->timestamp('last_checked_at')->nullable()->after('subscribed_at');
            $table->timestamp('last_notified_at')->nullable()->after('last_checked_at');
            $table->timestamp('unsubscribed_at')->nullable()->after('last_notified_at');

            $table->index(['kind', 'status', 'last_checked_at'], 'customer_alert_kind_status_checked_idx');
            $table->index(['product_id', 'variant_id', 'status'], 'customer_alert_product_variant_status_idx');
            $table->index(['user_id', 'kind', 'status'], 'customer_alert_user_kind_status_idx');
        });

        Schema::create('wishlist_price_drop_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_alert_subscription_id');
            $table->foreign('customer_alert_subscription_id', 'wishlist_price_drop_subscription_fk')
                ->references('id')->on('customer_alert_subscriptions')->cascadeOnDelete();
            $table->char('event_key', 64)->unique();
            $table->decimal('previous_price', 15, 2);
            $table->decimal('new_price', 15, 2);
            $table->char('currency', 3)->default('NGN');
            $table->string('status', 20)->default('detected');
            $table->timestamp('detected_at');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'detected_at']);
            $table->index(['customer_alert_subscription_id', 'detected_at'], 'wishlist_price_notification_subscription_idx');
        });

        Schema::create('wishlist_cart_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_key');
            $table->string('source', 30);
            $table->string('status', 20)->default('processing');
            $table->json('result')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'operation_key']);
            $table->index(['source', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_cart_operations');
        Schema::dropIfExists('wishlist_price_drop_notifications');

        Schema::table('customer_alert_subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['wishlist_item_id']);
            $table->dropIndex('customer_alert_kind_status_checked_idx');
            $table->dropIndex('customer_alert_product_variant_status_idx');
            $table->dropIndex('customer_alert_user_kind_status_idx');
            $table->dropUnique(['active_key']);
            $table->dropUnique(['unsubscribe_token']);
            $table->dropColumn([
                'wishlist_item_id', 'product_id', 'product_code', 'variant_key', 'variant_id', 'variant_code',
                'selected_attributes', 'currency', 'subscription_price', 'last_observed_price', 'last_notified_price',
                'active_key', 'unsubscribe_token', 'subscribed_at', 'last_checked_at', 'last_notified_at', 'unsubscribed_at',
            ]);
        });
    }
};
