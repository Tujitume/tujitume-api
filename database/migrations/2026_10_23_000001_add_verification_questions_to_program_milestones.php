<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_milestones', function (Blueprint $table) {
            $table->json('verification_questions')->nullable();
        });
        Schema::table('milestone_verifications', function (Blueprint $table) {
            $table->json('answers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('milestone_verifications', function (Blueprint $table) {
            $table->dropColumn('answers');
        });
        Schema::table('program_milestones', function (Blueprint $table) {
            $table->dropColumn('verification_questions');
        });
    }
};
