<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_latest_edit_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('product_id', 36)->unique();
            $table->string('product_code', 191)->index();
            $table->timestamp('included_at');
            $table->timestamp('expires_at')->nullable();
            $table->boolean('auto_expire')->default(true);
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_reason', 24)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['removed_at', 'auto_expire', 'expires_at'],
                'product_latest_edit_expiry_index'
            );
            $table->index(['included_at', 'product_id'], 'product_latest_edit_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_latest_edit_entries');
    }
};
