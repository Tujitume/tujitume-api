<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grants') && !Schema::hasTable('programs')) {
            Schema::rename('grants', 'programs');
        }

        if (Schema::hasTable('programs')) {
            Schema::table('programs', function (Blueprint $table): void {
                $columns = Schema::getColumnListing('programs');
                if (in_array('grant_title', $columns, true)) $table->renameColumn('grant_title', 'program_title');
                if (in_array('total_grant_amount', $columns, true)) $table->renameColumn('total_grant_amount', 'total_program_amount');
                if (in_array('grant_focus', $columns, true)) $table->renameColumn('grant_focus', 'program_focus');
                if (in_array('grant_brief_pdf', $columns, true)) $table->renameColumn('grant_brief_pdf', 'program_brief_pdf');
                if (in_array('grant_type', $columns, true)) $table->renameColumn('grant_type', 'program_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('programs')) {
            Schema::table('programs', function (Blueprint $table): void {
                $columns = Schema::getColumnListing('programs');
                if (in_array('program_title', $columns, true)) $table->renameColumn('program_title', 'grant_title');
                if (in_array('total_program_amount', $columns, true)) $table->renameColumn('total_program_amount', 'total_grant_amount');
                if (in_array('program_focus', $columns, true)) $table->renameColumn('program_focus', 'grant_focus');
                if (in_array('program_brief_pdf', $columns, true)) $table->renameColumn('program_brief_pdf', 'grant_brief_pdf');
                if (in_array('program_type', $columns, true)) $table->renameColumn('program_type', 'grant_type');
            });
        }

        if (Schema::hasTable('programs') && !Schema::hasTable('grants')) {
            Schema::rename('programs', 'grants');
        }
    }
};
