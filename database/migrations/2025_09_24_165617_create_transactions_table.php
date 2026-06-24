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
        Schema::create('transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Who initiated the transaction
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');

            // Optional: recipient (e.g., business or investor)
            $table->foreignId('recipient_id')->nullable()->constrained('users')->onDelete('cascade');

            // Type of transaction (categorize everything)
            $table->enum('type', [
                'deposit',
                'withdraw',
                'unlock_business',
                'investment', // investor pays into a business
                'investment_awaiting',
                'service_fee',
                'service_milestone',
                'grant_milestone',
                'grant_milestone_bulk',
                'capital_milestone',
                'subscription',
                'refund'
            ]);

            // Payment method or channel
            $table->string('method')->nullable();
            // e.g., 'stripe', 'lipr', 'manual', 'bank_transfer'

            // Amount fields
            $table->decimal('gross_amount', 15, 2);       // total amount
            $table->decimal('fee_amount', 15, 2)->default(0); // platform/service fee
            $table->decimal('net_amount', 15, 2);         // gross - fee
            $table->decimal('unsettled_amount', 15, 2)->default(0); // if any hold/unsettled

            // Status
            $table->enum('status', ['pending', 'processing', 'settled', 'processed', 'completed', 'failed', 'refunded'])
                ->default('settled');
            $table->enum('direction', ['debit', 'credit']); // money in or out

            // Optional: Reference to related entity (investment, milestone, etc.)
            $table->string('reference_id')->nullable();
            // creates `reference_id` and `reference_type`

            // Audit
            $table->integer('created_by')->nullable(); // null or 0 if admin/system
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
