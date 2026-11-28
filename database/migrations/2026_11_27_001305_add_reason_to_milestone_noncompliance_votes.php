<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An investor's reason for freezing or disputing a non-compliant milestone was asked for but never stored. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('milestone_noncompliance_votes', 'reason')) {
            Schema::table('milestone_noncompliance_votes', function (Blueprint $table) {
                $table->text('reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('milestone_noncompliance_votes', 'reason')) {
            Schema::table('milestone_noncompliance_votes', fn (Blueprint $table) => $table->dropColumn('reason'));
        }
    }
};
