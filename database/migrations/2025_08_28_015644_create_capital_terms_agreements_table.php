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
        Schema::create('capital_terms_agreements', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->unsignedInteger('capital_id');
            $table->foreign('capital_id')->references('id')->on('capital_offers')->onDelete('cascade');
            $table->foreignId('pitch_id')->constrained('startup_pitches')->cascadeOnDelete();
            $table->foreignId('business_owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('capital_owner_id')->constrained('users')->cascadeOnDelete();

            // Agreement Details
            $table->string('reason')->nullable()->default(null);
            $table->string('document')->nullable(); // uploaded offer/term sheet file
            $table->decimal('amount', 15, 2)->nullable(); // investment amount
            // Status Tracking
            $table->enum('status', [
                'submitted',        // uploaded by investor, waiting for business owner review
                'accepted',         // accepted by business owner
                'rejected',         // rejected by business owner
                'counter_submitted',// rejected by business owner
            ])->default('submitted');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capital_terms_agreements');
    }
};
