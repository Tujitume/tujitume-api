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
        Schema::create('reviewer_orders', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
        $table->foreignId('reviewer_id')->constrained('users')->onDelete('cascade');
        $table->foreignId('program_id')->constrained('programs')->onDelete('cascade');
        $table->enum('order_type', ['round_review', 'site_visit']);
        $table->foreignId('round_id')->nullable()->constrained('program_rounds')->onDelete('set null');
        $table->foreignId('site_visit_id')->nullable()->constrained('m_e_site_visits')->onDelete('set null');
        $table->decimal('fee_usd', 10, 2)->default(0);
        $table->decimal('fee_kes', 10, 2)->nullable();
        $table->string('currency', 10)->default('USD');
        $table->enum('work_status', [
            'assigned', 'in_progress', 'delivered',
            'modification_requested', 'approved', 'rejected',
        ])->default('assigned');
        $table->text('delivery_note')->nullable();
        $table->text('modification_note')->nullable();
        $table->text('rejection_reason')->nullable();
        $table->timestamp('deadline')->nullable();
        $table->timestamp('delivered_at')->nullable();
        $table->timestamp('approved_at')->nullable();
        $table->enum('payment_status', [
            'unpaid', 'pending', 'leg1_processing', 'completed', 'failed',
        ])->default('unpaid');
        $table->string('leg1_reference')->nullable();
        $table->string('leg2_reference')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();

        $table->unique(['reviewer_id', 'round_id'], 'unique_reviewer_round');
        $table->unique(['reviewer_id', 'site_visit_id'], 'unique_reviewer_site_visit');
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviewer_orders');
    }
};
