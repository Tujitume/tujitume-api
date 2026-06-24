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
        Schema::create('amap_flags', function (Blueprint $table) {
            $table->id();

            // What is flagged? (polymorphic)
            $table->string('flaggable_type');                      // User, Grant\Milestone, Business\Milestone
            $table->unsignedBigInteger('flaggable_id');

            $table->unsignedBigInteger('trigger_id')->nullable(); // originating trigger (optional link)

            // What kind of flag?
            $table->enum('flag_type', [
                'suspended',            // account/milestone suspended
                'blocked',              // blocked from actions
                'at_risk',              // high-risk entity
                'under_audit',          // currently being audited
            ]);

            // Status with independent lifecycle
            $table->enum('status', [
                'active',
                'expired',              // auto-expired after X days
                'lifted',               // manually removed
            ])->default('active');

            // When?
            $table->timestamp('flagged_at');
            $table->timestamp('expires_at')->nullable();           // auto-expire date (optional)
            $table->timestamp('lifted_at')->nullable();

            $table->unsignedBigInteger('lifted_by')->nullable();   // who manually lifted it

            $table->foreign('trigger_id')->references('id')->on('amap_triggers')->onDelete('set null');
            $table->index(['flaggable_type', 'flaggable_id', 'status']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amap_flags');
    }
};
