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
        Schema::create('amap_triggers', function (Blueprint $table) {
            $table->id();

            // What is affected? (polymorphic)
            $table->string('triggerable_type');           // Program\Milestone, Business\Milestone, Service\M, User
            $table->unsignedBigInteger('triggerable_id'); //Program\Milestone id

            // What happened?
            $table->enum('trigger_type', [
                'business_owner_silent',
                'supplier_non_payment_report',
                'supplier_non_delivery_report',
                'deadline_missed',
                'evidence_falsified',
                'repeated_rejections',
                'compliance_violation',
                'manual_flag',
            ]);

            $table->text('description')->nullable();                // brief explanation

            // Who reported it?
            $table->unsignedBigInteger('reported_by')->nullable(); // user_id (null = system-detected)

            // When?
            $table->timestamp('detected_at');

            // Current status
            $table->enum('status', [
                'active',
                'resolved',
                'dismissed',
            ])->default('active');

            $table->timestamp('resolved_at')->nullable();

            $table->index(['triggerable_type', 'triggerable_id']);
            $table->index('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amap_triggers');
    }
};
