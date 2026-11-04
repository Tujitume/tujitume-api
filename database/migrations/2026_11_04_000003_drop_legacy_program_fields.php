<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('programs')) {
            return;
        }

        $legacyColumns = [
            'eligibility_criteria',
            'required_documents',
            'program_focus',
            'regions',
            'startup_stage_focus',
            'impact_objectives',
            'social_impact_areas',
            'bonus_points',
            'evaluation_criteria',
            'funder_name',
            'funder_type',
            'program_brief_pdf',
            'start_date',
            'end_date',
            'mid_milestone_required',
        ];

        $hasLegacyData = false;
        foreach ($legacyColumns as $column) {
            if (Schema::hasColumn('programs', $column) && DB::table('programs')->whereNotNull($column)->where($column, '!=', '')->exists()) {
                $hasLegacyData = true;
                break;
            }
        }

        if ($hasLegacyData) {
            throw new \RuntimeException('Legacy program columns still contain data. Run the programs:migrate-legacy-fields command before dropping them.');
        }

        Schema::table('programs', function (Blueprint $table) use ($legacyColumns) {
            foreach ($legacyColumns as $column) {
                if (Schema::hasColumn('programs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        // Contract migration is intentionally destructive. The legacy columns are removed and not restored to avoid
        // reintroducing the deprecated schema during rollback; older exported backups remain on disk.
    }
};
