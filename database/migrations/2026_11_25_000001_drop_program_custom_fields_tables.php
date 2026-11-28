<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// Program "Application fields" were removed: applicant questions live in each round's configuration (Questions step).
// Dropping these tables deletes any stored field definitions and answers; it cannot be undone.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('program_custom_field_values');
        Schema::dropIfExists('program_custom_fields');
    }

    public function down(): void
    {
        // Not restored: the feature and its code are gone.
    }
};
