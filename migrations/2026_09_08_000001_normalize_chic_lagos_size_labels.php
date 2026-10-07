<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mshop_attribute')) {
            return;
        }

        foreach (['8', '10', '12', '14', '16', '18'] as $size) {
            DB::table('mshop_attribute')
                ->where('domain', 'product')
                ->where('type', 'size')
                ->where('code', 'chiclagos-size-'.$size)
                ->update(['label' => $size, 'mtime' => now()]);
        }
    }

    public function down(): void
    {
        // Numeric labels are the Chic Lagos business convention. Rolling the
        // application back must not silently restore the rejected UK prefix.
    }
};
