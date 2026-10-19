<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviewer_orders', function (Blueprint $table) {
            $table->unsignedInteger('minimum_apps_to_get_paid')->nullable()->after('fee_type');
        });
        Schema::table('round_reviewers', function (Blueprint $table) {
            $table->unsignedInteger('minimum_apps_to_get_paid')->nullable()->after('max_apps_assigned');
        });
    }

    public function down(): void
    {
        Schema::table('reviewer_orders', function (Blueprint $table) {
            $table->dropColumn('minimum_apps_to_get_paid');
        });
        Schema::table('round_reviewers', function (Blueprint $table) {
            $table->dropColumn('minimum_apps_to_get_paid');
        });
    }
};
