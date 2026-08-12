<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */

    /**
     * Reverse the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE service_books MODIFY COLUMN status ENUM('pending','confirmed','paid','in_progress','done','completed') DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE service_books MODIFY COLUMN status ENUM('pending','confirmed','paid','in_progress','done') DEFAULT 'pending'");
    }
};
