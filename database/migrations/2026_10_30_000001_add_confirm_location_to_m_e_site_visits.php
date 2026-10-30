<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_e_site_visits', function (Blueprint $table) {
            $table->boolean('confirm_location')->default(false)->after('gps_lng');
        });
    }

    public function down(): void
    {
        Schema::table('m_e_site_visits', function (Blueprint $table) {
            $table->dropColumn('confirm_location');
        });
    }
};
