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
        Schema::create('amap_actions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('trigger_id');              // which trigger caused this

            // What action was taken?
            $table->enum('action_type', [
                'freeze_milestones',
                'suspend_business_owner',
                'request_audit',
                'block_future_programs',
                'flag_at_risk',
                'notify_program_owner',
                'withhold_payment',
            ]);

            $table->text('details')->nullable();                   // what exactly happened

            // When?
            $table->timestamp('executed_at');
            $table->timestamp('reversed_at')->nullable();          // if action was undone

            $table->foreign('trigger_id')->references('id')->on('amap_triggers')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amap_actions');
    }
};
