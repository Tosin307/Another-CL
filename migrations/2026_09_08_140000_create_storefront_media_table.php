<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_media', function (Blueprint $table): void {
            $table->id();
            $table->string('placement', 120)->unique();
            $table->string('label');
            $table->string('media_path', 2048);
            $table->string('media_type', 12);
            $table->string('mime_type', 100);
            $table->string('poster_path', 2048)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_media');
    }
};
