<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grant_milestones') && !Schema::hasTable('program_milestones')) {
            Schema::rename('grant_milestones', 'program_milestones');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('program_milestones') && !Schema::hasTable('grant_milestones')) {
            Schema::rename('program_milestones', 'grant_milestones');
        }
    }
};
