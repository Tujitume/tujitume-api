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
        Schema::create('supplier_directories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Grant owner

            // Basic Identity
            $table->string('legal_name', 255);
            $table->string('contact_person', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('supplier_type', 100)->nullable(); // Materials, Consultant, Contractor

            $table->string('city', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('address', 100)->nullable();

            // Payment Method
            $table->enum('payment_method', [
                'mpesa_lipr',
                'mpesa_paybill',
                'mpesa_till',
                'mpesa_mobile',
                'bank_transfer',
                'other',
            ])->nullable();

            // M-Pesa Details
            $table->string('lipr_wallet', 30)->nullable();
            $table->string('lipr_mobile_number', 30)->nullable();
            $table->string('mpesa_paybill_number', 20)->nullable();
            $table->string('mpesa_paybill_account', 20)->nullable();
            $table->string('mpesa_till_number', 20)->nullable();
            $table->string('mpesa_account_reference', 100)->nullable();

            // Bank Details
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_number', 50)->nullable();
            $table->string('bank_branch', 100)->nullable();
            $table->string('bank_swift_code', 20)->nullable();

            // Internal
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index('legal_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_directories');
    }
};
