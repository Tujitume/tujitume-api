<?php

use App\Models\Programs\Program;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the core program fields introduced by the configurable custom-field system.
     */
    public function up(): void
    {
        $table = (new Program())->getTable();

        Schema::table($table, function (Blueprint $table) {
            if (! Schema::hasColumn($table->getTable(), 'application_start_date')) {
                $table->date('application_start_date')->nullable()->after('program_title');
            }

            if (! Schema::hasColumn($table->getTable(), 'require_kyc_verification_to_apply')) {
                $table->boolean('require_kyc_verification_to_apply')->default(false)->after('application_start_date');
            }
        });
    }

    public function down(): void
    {
        $table = (new Program())->getTable();

        Schema::table($table, function (Blueprint $table) {
            if (Schema::hasColumn($table->getTable(), 'require_kyc_verification_to_apply')) {
                $table->dropColumn('require_kyc_verification_to_apply');
            }

            if (Schema::hasColumn($table->getTable(), 'application_start_date')) {
                $table->dropColumn('application_start_date');
            }
        });
    }
};
