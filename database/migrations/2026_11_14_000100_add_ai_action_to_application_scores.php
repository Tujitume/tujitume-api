<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A Business Coach's score can start from the AI's suggestion. This records what they did with it: approved it as it was, or edited it.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('application_scores', 'ai_action')) {
            Schema::table('application_scores', function (Blueprint $table) {
                $table->string('ai_action', 12)->nullable(); // approved | edited | null (not from an AI suggestion)
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('application_scores', 'ai_action')) {
            Schema::table('application_scores', function (Blueprint $table) {
                $table->dropColumn('ai_action');
            });
        }
    }
};
