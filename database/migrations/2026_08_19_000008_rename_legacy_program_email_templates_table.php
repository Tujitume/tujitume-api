<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grant_email_templates') && !Schema::hasTable('program_email_templates')) {
            Schema::rename('grant_email_templates', 'program_email_templates');
        }

        if (Schema::hasTable('program_email_templates') && Schema::hasColumn('program_email_templates', 'grant_id')) {
            Schema::table('program_email_templates', fn ($table) => $table->renameColumn('grant_id', 'program_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('program_email_templates') && Schema::hasColumn('program_email_templates', 'program_id')) {
            Schema::table('program_email_templates', fn ($table) => $table->renameColumn('program_id', 'grant_id'));
        }

        if (Schema::hasTable('program_email_templates') && !Schema::hasTable('grant_email_templates')) {
            Schema::rename('program_email_templates', 'grant_email_templates');
        }
    }
};
