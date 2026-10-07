<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_branding', function (Blueprint $table): void {
            $table->id();
            $table->string('logo_path', 2048)->nullable();
            $table->string('logo_mime', 100)->nullable();
            $table->string('logo_alt', 255)->default('Chic Lagos');
            $table->unsignedSmallInteger('desktop_width')->default(180);
            $table->unsignedSmallInteger('tablet_width')->default(155);
            $table->unsignedSmallInteger('mobile_width')->default(132);
            $table->unsignedSmallInteger('desktop_padding')->default(0);
            $table->unsignedSmallInteger('tablet_padding')->default(0);
            $table->unsignedSmallInteger('mobile_padding')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_branding');
    }
};
