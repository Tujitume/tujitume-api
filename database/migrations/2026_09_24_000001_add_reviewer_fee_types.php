<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('round_reviewers', function (Blueprint $table) {
            $table->enum('fee_type', ['flat', 'per_application'])->default('flat')->after('reviewer_fee');
        });

        Schema::table('reviewer_orders', function (Blueprint $table) {
            $table->enum('fee_type', ['flat', 'per_application'])->default('flat')->after('fee_usd');
        });
    }

    public function down(): void
    {
        Schema::table('reviewer_orders', function (Blueprint $table) {
            $table->dropColumn('fee_type');
        });

        Schema::table('round_reviewers', function (Blueprint $table) {
            $table->dropColumn('fee_type');
        });
    }
};
