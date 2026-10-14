<?php

use App\Models\Programs\Rounds\ProgramRound;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The round wizard steps a person has confirmed (saved or moved on from). A step with valid data is
     * still not "done" until it is confirmed, because the first round is created with the program.
     */
    public function up(): void
    {
        $table = (new ProgramRound())->getTable();

        if (Schema::hasColumn($table, 'confirmed_steps')) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            $table->json('confirmed_steps')->nullable();
        });
    }

    public function down(): void
    {
        $table = (new ProgramRound())->getTable();

        if (! Schema::hasColumn($table, 'confirmed_steps')) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            $table->dropColumn('confirmed_steps');
        });
    }
};
