<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_industries', function (Blueprint $table) {
            if (!Schema::hasColumn('program_industries', 'url')) {
                $table->string('url')->nullable()->unique()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('program_industries', function (Blueprint $table) {
            if (Schema::hasColumn('program_industries', 'url')) {
                $table->dropColumn('url');
            }
        });
    }
};
