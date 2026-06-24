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
        Schema::create('milestone_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_id')->constrained('grant_milestones')->onDelete('cascade');
            $table->foreignId('supplier_id')->constrained('supplier_directories')->onDelete('restrict');

            // Assignment metadata (NEW)
            $table->enum('assignment_type', ['primary', 'approved', 'preferred'])->default('approved');
            $table->boolean('is_locked')->default(false);
            $table->enum('payment_route', ['direct_to_supplier', 'split', 'direct_to_applicant'])
                ->default('direct_to_supplier');

            // Milestone-specific documents (these vary per milestone)
            $table->string('invoice_file', 255)->nullable();
            $table->string('quotation_file', 255)->nullable();
            $table->decimal('quoted_amount', 15, 2)->nullable();

            // Conflict of interest (per milestone)
            $table->tinyInteger('conflict_of_interest_declared')->default(0);
            $table->text('conflict_of_interest_notes')->nullable();

            $table->enum('added_by', ['grant_owner', 'applicant'])->default('grant_owner');

            $table->timestamps();

            $table->unique(['milestone_id', 'supplier_id']); // One supplier per milestone
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_suppliers');
    }
};
