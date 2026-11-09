<?php

use App\Models\Services\ServiceCategory;
use Illuminate\Database\Migrations\Migration;

// Service providers list this category when they coach businesses; organizations find them by it when they search.
return new class extends Migration
{
    public function up(): void
    {
        $category = ServiceCategory::where('value', 'business_coaching')->first();
        if ($category) {
            $category->update(['name' => 'Business Coach']);
        } else {
            ServiceCategory::create(['name' => 'Business Coach', 'value' => 'business_coaching']);
        }
    }

    public function down(): void
    {
        ServiceCategory::where('value', 'business_coaching')->update(['name' => 'Business Coaching']);
    }
};
