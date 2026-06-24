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
        Schema::create('milestone_rmeps', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('milestone_id'); // references milestones.id
            $table->string('milestone_name');
            $table->text('description')->nullable();
            $table->text('rmep_document')->nullable();


            $table->date('voting_deadline')->nullable();
            $table->enum('status', ['active', 'closed', 'pending','approved', 'rejected'])->default('active');
            //$table->enum('outcome', ['pending', 'approved', 'rejected'])->default('pending');

            $table->json('eligible_voters')->nullable(); // store IDs as JSON
            //$table->integer('total_eligible')->default(0);
            $table->integer('total_voted')->default(0);
            $table->integer('total_pending')->default(0);

            $table->integer('approve_count')->default(0);
            $table->integer('reject_count')->default(0);
            $table->foreign('milestone_id')->references('id')->on('milestones')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_rmeps');
    }
};
