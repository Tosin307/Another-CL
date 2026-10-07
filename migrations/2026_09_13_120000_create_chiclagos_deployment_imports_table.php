<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chiclagos_deployment_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('version', 120)->unique();
            $table->string('payload_sha256', 64);
            $table->json('summary');
            $table->timestamp('imported_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chiclagos_deployment_imports');
    }
};
