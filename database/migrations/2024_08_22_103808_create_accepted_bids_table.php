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
        Schema::create('accepted_bids', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('bid_id')->nullable();
            $table->unsignedBigInteger('ms_id')->nullable();
            $table->string('date', 255)->nullable();
            $table->unsignedInteger('investor_id')->nullable();
            $table->unsignedBigInteger('business_id')->nullable();
            $table->unsignedInteger('owner_id')->nullable();
            $table->string('stripe_charge_id', 100)->nullable();
            $table->unsignedInteger('lipr_transaction_id')->nullable();
            $table->string('type', 255)->nullable();
            $table->string('method', 255)->nullable();
            $table->unsignedInteger('amount')->nullable();
            $table->decimal('representation', 10, 2)->nullable();
            $table->string('photos', 1000)->nullable();
            $table->string('legal_doc', 255)->nullable();
            $table->string('serial', 700)->nullable();
            $table->string('optional_doc', 255)->nullable();
            $table->boolean('paid_in_full')->default(false);
            $table->unsignedTinyInteger('next_mile_agree')->nullable();
            $table->unsignedTinyInteger('project_manager')->nullable();
            $table->unsignedTinyInteger('payment_released')->nullable();
            $table->string('status', 50)->default('awaiting_payment');
            $table->timestamps();

            $table->foreign('ms_id')
                ->references('id')->on('milestones')
                ->onDelete('cascade'); // DELETE accepted bids if milestone deleted

            $table->foreign('business_id')
                ->references('id')->on('listings')
                ->onDelete('cascade'); // DELETE accepted bids if listing deleted
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accepted_bids');
    }
};
