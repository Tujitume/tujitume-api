<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Service providers list this category when they coach businesses; organizations find them by it when they search.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_categories')) {
            return;
        }

        $exists = DB::table('service_categories')->where('value', 'business_coaching')->exists();
        if ($exists) {
            DB::table('service_categories')->where('value', 'business_coaching')->update(['name' => 'Business Coach']);
        } else {
            DB::table('service_categories')->insert(['name' => 'Business Coach', 'value' => 'business_coaching']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('service_categories')) {
            DB::table('service_categories')->where('value', 'business_coaching')->update(['name' => 'Business Coaching']);
        }
    }
};
