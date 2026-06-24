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
        Schema::table('grant_wallets', function (Blueprint $table) {
            // Drop existing foreign key with RESTRICT
            $table->dropForeign(['grant_id']);

            // Re-add foreign key with CASCADE
            $table->foreign('grant_id')
                ->references('id')
                ->on('grants')
                ->onDelete('cascade'); // Changed from RESTRICT to CASCADE
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grant_wallets', function (Blueprint $table) {
            // Drop CASCADE foreign key
            $table->dropForeign(['grant_id']);

            // Restore RESTRICT foreign key
            $table->foreign('grant_id')
                ->references('id')
                ->on('grants')
                ->onDelete('restrict');
        });
    }
};
