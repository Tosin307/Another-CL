<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_merchandising_offers', function (Blueprint $table): void {
            $table->id();
            $table->string('product_id', 36);
            $table->string('product_code', 191);
            $table->string('offer_type', 32);
            $table->boolean('active')->default(false);
            $table->string('label', 191)->nullable();
            $table->string('related_product_id', 36)->nullable();
            $table->string('related_product_code', 191)->nullable();
            $table->unsignedTinyInteger('discount_percent')->nullable();
            $table->unsignedSmallInteger('max_gift_quantity')->nullable();
            $table->json('thresholds')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('counter_enabled')->default(false);
            $table->string('counter_label', 191)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'offer_type'], 'product_merch_offer_product_type_unique');
            $table->index(['offer_type', 'active', 'starts_at', 'ends_at'], 'product_merch_offer_active_window_index');
            $table->index('related_product_id', 'product_merch_offer_related_index');
        });

        Schema::create('product_information_panels', function (Blueprint $table): void {
            $table->id();
            $table->string('product_id', 36);
            $table->string('product_code', 191);
            $table->string('panel_key', 64);
            $table->string('title', 191);
            $table->string('teaser', 255)->nullable();
            $table->text('body')->nullable();
            $table->json('tags')->nullable();
            $table->json('details')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'panel_key'], 'product_information_product_key_unique');
            $table->index(['product_id', 'active', 'position'], 'product_information_product_position_index');
        });

        Schema::create('product_review_metadata', function (Blueprint $table): void {
            $table->id();
            $table->string('review_id', 36)->unique();
            $table->string('product_id', 36)->index();
            $table->text('reviewer_email')->nullable();
            $table->string('title', 191)->nullable();
            $table->string('location', 120)->nullable();
            $table->string('size', 32)->nullable();
            $table->string('media_path', 255)->nullable();
            $table->string('media_type', 16)->nullable();
            $table->boolean('verified_purchase')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('product_merchandising_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 191)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('product_id', 36)->nullable();
            $table->string('product_code', 191);
            $table->string('related_product_id', 36)->nullable();
            $table->string('related_product_code', 191)->nullable();
            $table->string('offer_type', 32);
            $table->string('event_type', 64);
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('order_id', 36)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['product_id', 'occurred_at'], 'product_merch_event_product_time_index');
            $table->index(['offer_type', 'event_type', 'occurred_at'], 'product_merch_event_offer_type_time_index');
            $table->index('order_id', 'product_merch_event_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_merchandising_events');
        Schema::dropIfExists('product_review_metadata');
        Schema::dropIfExists('product_information_panels');
        Schema::dropIfExists('product_merchandising_offers');
    }
};
