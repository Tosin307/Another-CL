<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_reservations', function (Blueprint $table) {
            $table->json('allocation')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_reservations', function (Blueprint $table) {
            $table->dropColumn('allocation');
        });
    }
};
