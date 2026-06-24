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
        Schema::create('milestone_pre_release_docs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');

            // Written Statement (usually text or file)
            $table->text('written_statement')->nullable();

            // === B. Proof of Planned Procurement Uploads ===
            $table->text('quotation')->nullable();            //
            $table->text('proforma_invoice')->nullable();     //
            $table->text('vendor_estimate')->nullable();
            $table->text('mou_supplier_confirm')->nullable();

            // === C. Financial Reasonableness Check Uploads ===
            $table->text('industry_benchmark')->nullable();
            $table->text('prior_proposal_align')->nullable();
            $table->text('project_budget_match')->nullable();

            // === D. Risk Flags / Compliance Items ===
            $table->text('kra_pin')->nullable();
            $table->text('business_registration')->nullable();
            $table->text('updated_budget')->nullable();
            $table->text('project_plan_details')->nullable();

            // === E. Optional Media Proof ===
            $table->text('photo_location')->nullable();
            $table->text('photo_equipment')->nullable();
            $table->text('photo_current_state')->nullable();

            // Status
            $table->boolean('is_complete')->default(false);

            $table->timestamps();

            $table->foreign('request_id')
                ->references('id')->on('milestone_pre_release_requests')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_pre_release_docs');
    }
};
