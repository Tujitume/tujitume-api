<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business verification (KYB) details the Add/Edit Business form already asks for,
 * but that had no columns so they were never saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            if (! Schema::hasColumn('listings', 'business_type')) {
                $table->string('business_type', 60)->nullable()->after('stage');
            }
            if (! Schema::hasColumn('listings', 'registration_number')) {
                $table->string('registration_number', 100)->nullable()->after('business_type');
            }
            if (! Schema::hasColumn('listings', 'registration_country')) {
                $table->char('registration_country', 2)->nullable()->after('registration_number');
            }
            if (! Schema::hasColumn('listings', 'proof_of_address')) {
                $table->string('proof_of_address')->nullable()->after('document');
            }
            if (! Schema::hasColumn('listings', 'business_registration_certificate')) {
                $table->string('business_registration_certificate')->nullable()->after('proof_of_address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            foreach (['business_type', 'registration_number', 'registration_country', 'proof_of_address', 'business_registration_certificate'] as $column) {
                if (Schema::hasColumn('listings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
