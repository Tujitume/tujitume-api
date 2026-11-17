<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applications made while the apply form filled an empty sector or place with the words "Not specified" stored those words
 * as if they were answers, and every table showed them. An empty value is stored as an empty string now; this clears the old ones.
 * Safe to run more than once. Nothing is lost: the words carried no information.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sector', 'headquarters_location'] as $column) {
            DB::table('program_applications')->whereRaw("LOWER(TRIM($column)) = 'not specified'")->update([$column => '']);
        }
    }

    public function down(): void
    {
        // The placeholder text is not restored.
    }
};
