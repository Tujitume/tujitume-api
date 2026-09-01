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
        Schema::table('round_reviewers', function (Blueprint $table) {
            $table->decimal('reviewer_fee', 10, 2)->nullable()->after('max_apps_assigned');
            $table->string('fee_currency', 10)->default('USD')->after('reviewer_fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('round_reviewers', function (Blueprint $table) {
            $table->dropColumn(['reviewer_fee', 'fee_currency']);
        });
    }
};
