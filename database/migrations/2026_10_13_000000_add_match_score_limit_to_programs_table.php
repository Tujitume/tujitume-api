<?php

use App\Models\Programs\Program;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The match score an applicant must reach before the program's bonus points count (1 to 100).
     * Empty means no limit, which is how every existing program behaves, so nothing changes for them.
     */
    public function up(): void
    {
        $table = (new Program())->getTable();

        if (Schema::hasColumn($table, 'match_score_limit')) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            $table->unsignedTinyInteger('match_score_limit')->nullable();
        });
    }

    public function down(): void
    {
        $table = (new Program())->getTable();

        if (Schema::hasColumn($table, 'match_score_limit')) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('match_score_limit');
            });
        }
    }
};
