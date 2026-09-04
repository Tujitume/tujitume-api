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

            // ─── Parties ──────────────────────────────────────────────────
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('reviewer_id')->constrained('users')->onDelete('cascade');   // external_reviewer
            $table->foreignId('program_id')->constrained('programs')->onDelete('cascade');

            // ─── Scope (what they are being paid for) ─────────────────────
            $table->enum('order_type', ['round_review', 'site_visit']);
            $table->foreignId('round_id')->nullable()->constrained('program_rounds')->onDelete('set null');
            $table->foreignId('site_visit_id')->nullable()->constrained('m_e_site_visits')->onDelete('set null');

            // ─── Pricing ──────────────────────────────────────────────────
            $table->decimal('fee_usd', 10, 2);                   // agreed fee in USD
            $table->decimal('fee_kes', 10, 2)->nullable();        // converted at payment time
            $table->string('currency', 10)->default('USD');

            // ─── Work Status ──────────────────────────────────────────────
            $table->enum('work_status', [
                'assigned',           // reviewer just assigned
                'in_progress',        // reviewer working
                'delivered',          // reviewer submitted/marked done
                'modification_requested', // PO wants changes
                'approved',           // PO approved the work
                'rejected',           // PO rejected
            ])->default('assigned');

            $table->text('delivery_note')->nullable();         // reviewer's note on delivery
            $table->text('modification_note')->nullable();     // PO's note when requesting changes
            $table->text('rejection_reason')->nullable();      // PO's reason for rejection

            $table->timestamp('deadline')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('approved_at')->nullable();

            // ─── Payment Status ───────────────────────────────────────────
            $table->enum('payment_status', [
                'unpaid',
                'pending',            // STK push initiated
                'leg1_processing',    // STK push received, W2W initiated
                'completed',          // reviewer received funds
                'failed',
            ])->default('unpaid');

            $table->string('leg1_reference')->nullable();     // STK push reference
            $table->string('leg2_reference')->nullable();     // W2W transfer reference
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            // ─── Constraints ──────────────────────────────────────────────
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
