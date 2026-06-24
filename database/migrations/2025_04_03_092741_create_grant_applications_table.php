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
        Schema::create('grant_applications', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('grant_id');
            $table->unsignedBigInteger('business_id')->nullable();
            $table->unsignedBigInteger('user_id');                      // applicant (business owner)
            $table->unsignedBigInteger('grant_owner_id');               // grant admin reviewing this

            // Business / startup profile
            $table->string('startup_name', 255);
            $table->string('contact_person_name', 100)->nullable();
            $table->string('contact_person_email', 100)->nullable();
            $table->string('sector', 100);
            $table->string('headquarters_location', 255);
            $table->string('stage', 100)->nullable();

            // Financials & traction
            $table->decimal('revenue_last_12_months', 15, 2)->nullable()->default(0.00);
            $table->integer('team_experience_avg_years')->nullable();
            $table->text('traction_kpis')->nullable();

            // Submitted files
            $table->string('pitch_deck_file', 255)->nullable();
            $table->string('pitch_video', 255)->nullable();
            $table->string('business_plan_file', 255)->nullable();

            // Impact
            $table->json('social_impact_areas')->nullable();
            $table->string('bonus_points', 300)->nullable();

            // Funding
            $table->decimal('total_amount_requested', 15, 2);           // was float → decimal
            $table->decimal('awarded_amount', 15, 2)->nullable();        // new: actual amount awarded

            // Scoring
            $table->unsignedInteger('match_score')->nullable();
            $table->json('score_breakdown')->nullable();                  // was string(100) → json
            $table->text('reviewer_notes')->nullable();                   // new: internal reviewer notes

            // Status — was tinyInt, now full ENUM pipeline
            $table->enum('status', [
                'draft',            // saved, not yet submitted
                'pending',        // submitted by business owner
                'under_review',     // being evaluated
                'approved',         // grant owner approved
                'rejected',         // grant owner rejected
                'waitlisted',
                'awarded',          // funds allocated, milestones can begin
                'withdrawn',        // applicant withdrew
            ])->default('draft');

            $table->enum('funding_setup_status', [

                'not_started',        // Just approved

                'in_progress',        // Grant owner preparing milestones

                'awaiting_applicant_revision', // Grant owner finalized setup, waiting for applicant revisions/submission

                'awaiting_owner_review',    // Applicant submitted revised plan

                'revision_requested',       // Owner requested revision

                'completed'           // Fully approved and execution-ready

            ])->default('not_started');

            $table->enum('knockout_status', ['pending', 'passed', 'failed'])->default('pending');


            $table->enum('planning_mode', ['locked', 'hybrid'])->de();

            $table->text('rejection_reason')->nullable();
            // new
            $table->foreignId('current_round_id')->nullable()->constrained('grant_rounds');

            $table->enum('round_status', ['draft', 'submitted', 'under_review', 'scored', 'advanced', 'not_selected', 'ineligible', 'withdrawn'])->default('draft');

            $table->decimal('average_score', 5, 2)->nullable();
            $table->boolean('is_eligible_to_advance')->default(false);

            $table->boolean('escrow_funded')->default(false);
            $table->timestamp('escrow_funded_at')->nullable();
            $table->decimal('escrow_amount', 15, 2)->nullable(); // total amount held in escrow

            $table->index(['grant_owner_id', 'status']);

            $table->foreign('grant_id')->references('id')->on('grants')->onDelete('restrict');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('grant_owner_id')->references('id')->on('users')->onDelete('restrict');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grant_applications');
    }
};
