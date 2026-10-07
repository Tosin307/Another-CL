<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_audit_events', function (Blueprint $table) {
            $table->id();
            // Deliberately no foreign keys: financial audit snapshots must
            // survive ordinary customer, wallet and transaction deletion.
            $table->unsignedBigInteger('wallet_transaction_id')->unique();
            $table->unsignedBigInteger('wallet_id_snapshot')->index();
            $table->unsignedBigInteger('user_id_snapshot')->index();
            $table->string('event_type', 40);
            $table->string('status', 30);
            $table->string('reference', 120)->nullable()->index();
            $table->string('order_id', 120)->nullable()->index();
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->char('currency', 3);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_audit_events');
    }
};
