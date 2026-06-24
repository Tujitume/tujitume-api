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
        Schema::create('disbursements', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('milestone_id');
            $table->unsignedBigInteger('wallet_id')->nullable();                    // which grant wallet
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('budget_item_id')->nullable();

            // Who receives the money (supplier-first model)
            $table->enum('recipient_type', [
                'supplier',             // default
                'business_owner',       // exception only — must be justified
            ])->default('supplier');

            $table->unsignedBigInteger('recipient_user_id')->nullable(); // if business_owner exception

            // Payment
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3)->default('KES');

            $table->enum('payment_method', [
                'mpesa_paybill',
                'mpesa_mobile',
                'mpesa_till',
                'bank_transfer',
                'pochi',
                'other',
            ]);

            // Traceability (section 9.2 — every payment gets these 3)
            $table->string('payment_reference', 100)->nullable();       // M-Pesa ref / bank ref
            $table->string('receipt_file', 255)->nullable();            // receipt document
            $table->timestamp('disbursed_at')->nullable();                   // exact payment timestamp

            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
                'reversed',
            ])->default('pending');

            $table->text('failure_reason')->nullable();
            $table->boolean('supplier_confirmed')->default(false); // has supplier confirmed receipt?

            // Exception justification (section 9.3 — beneficiary payment)
            $table->text('beneficiary_payment_justification')->nullable();
            $table->unsignedBigInteger('authorized_by')->nullable();    // grant owner who approved exception

            $table->foreign('milestone_id')
                ->references('id')->on('grant_milestones')->onDelete('restrict');
            $table->foreign('wallet_id')
                ->references('id')->on('grant_wallets')->onDelete('restrict');
            $table->foreign('supplier_id')
                ->references('id')->on('milestone_suppliers')->onDelete('set null');
            $table->foreign('authorized_by')
                ->references('id')->on('users')->onDelete('set null');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disbursements');
    }
};
