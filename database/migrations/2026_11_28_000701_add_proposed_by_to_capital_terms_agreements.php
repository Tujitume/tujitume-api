<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who proposed a capital terms version (provider | applicant). It used to be guessed from the status, so once a
 * counter-offer was accepted or declined it showed as "from the provider".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capital_terms_agreements', function (Blueprint $table) {
            if (! Schema::hasColumn('capital_terms_agreements', 'proposed_by')) $table->string('proposed_by', 20)->nullable();
        });
        // the versions still waiting say who sent them; answered older ones stay unknown (the API falls back)
        DB::table('capital_terms_agreements')->whereNull('proposed_by')->where('status', 'counter_submitted')->update(['proposed_by' => 'applicant']);
        DB::table('capital_terms_agreements')->whereNull('proposed_by')->where('status', 'submitted')->update(['proposed_by' => 'provider']);
    }

    public function down(): void
    {
        Schema::table('capital_terms_agreements', function (Blueprint $table) {
            if (Schema::hasColumn('capital_terms_agreements', 'proposed_by')) $table->dropColumn('proposed_by');
        });
    }
};
