<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_merchandising_relations', function (Blueprint $table): void {
            $table->id();
            $table->string('product_id', 36);
            $table->string('product_code', 191);
            $table->string('relation_type', 32);
            $table->string('related_product_id', 36);
            $table->string('related_product_code', 191);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'relation_type', 'related_product_id'], 'product_merch_relation_unique');
            $table->index(['product_id', 'relation_type', 'active', 'position'], 'product_merch_relation_lookup');
            $table->index(['related_product_id', 'relation_type'], 'product_merch_relation_reverse_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_merchandising_relations');
    }
};
