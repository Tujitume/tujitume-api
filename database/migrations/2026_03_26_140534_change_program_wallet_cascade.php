<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('program_wallets', function (Blueprint $table) {
            // Drop existing foreign key with RESTRICT
            $table->dropForeign(['program_id']);

            // Re-add foreign key with CASCADE
            $table->foreign('program_id')
                ->references('id')
                ->on('programs')
                ->onDelete('cascade'); // Changed from RESTRICT to CASCADE
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('program_wallets', function (Blueprint $table) {
            // Drop CASCADE foreign key
            $table->dropForeign(['program_id']);

            // Restore RESTRICT foreign key
            $table->foreign('program_id')
                ->references('id')
                ->on('programs')
                ->onDelete('restrict');
        });
    }
};
