<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_e_checkpoints', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('respondent')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('m_e_checkpoints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
        });
    }
};
