<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The monitoring hand-off columns were later folded into the create-table migrations. A database whose tables were
// created before that never gets them from those files, so add each one here when it is missing (nothing else changes).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('m_e_checkpoints') && ! Schema::hasColumn('m_e_checkpoints', 'assigned_to')) {
            Schema::table('m_e_checkpoints', function (Blueprint $table) {
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            });
        }
        if (Schema::hasTable('m_e_submissions') && ! Schema::hasColumn('m_e_submissions', 'flagged_items')) {
            Schema::table('m_e_submissions', function (Blueprint $table) {
                $table->json('flagged_items')->nullable();
            });
        }
        if (Schema::hasTable('reviewer_orders') && ! Schema::hasColumn('reviewer_orders', 'flagged_items')) {
            Schema::table('reviewer_orders', function (Blueprint $table) {
                $table->json('flagged_items')->nullable();
            });
        }
        if (Schema::hasTable('m_e_site_visits') && ! Schema::hasColumn('m_e_site_visits', 'confirm_location')) {
            Schema::table('m_e_site_visits', function (Blueprint $table) {
                $table->boolean('confirm_location')->default(false);
            });
        }
    }

    public function down(): void
    {
        // Additive only: the columns belong to the create-table migrations.
    }
};
