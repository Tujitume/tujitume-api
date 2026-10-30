<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE m_e_checkpoints MODIFY type VARCHAR(40) NOT NULL");
        }
        Schema::table('m_e_checkpoints', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable();
            $table->string('respondent', 30)->default('applicant');
        });
        DB::table('m_e_checkpoints')->whereNull('sent_at')->update([
            'sent_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)'),
        ]);
        DB::table('m_e_checkpoints')->where('require_site_visit', true)->update(['respondent' => 'site_visit_officer']);
        Schema::table('m_e_submissions', function (Blueprint $table) {
            $table->json('flagged_items')->nullable();
        });
        Schema::table('reviewer_orders', function (Blueprint $table) {
            $table->json('flagged_items')->nullable();
            $table->decimal('proposed_fee', 12, 2)->nullable();
            $table->string('fee_status', 30)->nullable();
            $table->text('fee_proposal_note')->nullable();
            $table->timestamp('fee_proposed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reviewer_orders', function (Blueprint $table) {
            $table->dropColumn(['flagged_items', 'proposed_fee', 'fee_status', 'fee_proposal_note', 'fee_proposed_at']);
        });
        Schema::table('m_e_submissions', fn (Blueprint $table) => $table->dropColumn('flagged_items'));
        Schema::table('m_e_checkpoints', fn (Blueprint $table) => $table->dropColumn('sent_at'));
    }
};
