<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paystack_payments', function (Blueprint $table): void {
            $table->string('source_basket_id', 255)->nullable()->after('order_id');
            $table->string('source_basket_suffix', 190)->nullable()->after('source_basket_id');
            $table->timestamp('cart_finalized_at')->nullable()->after('paid_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('paystack_payments', function (Blueprint $table): void {
            $table->dropIndex(['cart_finalized_at']);
            $table->dropColumn(['source_basket_id', 'source_basket_suffix', 'cart_finalized_at']);
        });
    }
};
