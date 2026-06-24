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
        Schema::create('business_bids', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('date', 255)->nullable();
            $table->unsignedInteger('investor_id')->nullable();
            $table->unsignedInteger('business_id')->nullable();
            $table->unsignedInteger('ms_id')->nullable();
            $table->unsignedInteger('owner_id')->nullable();
            $table->string('type', 255)->nullable();
            $table->string('method', 255)->nullable();
            $table->unsignedInteger('amount')->nullable();
            $table->decimal('representation', 10, 2)->nullable();
            $table->string('photos', 1000)->nullable();
            $table->string('legal_doc', 255)->nullable();
            $table->string('serial', 700)->nullable();
            $table->string('optional_doc', 255)->nullable();
            $table->string('stripe_charge_id', 255)->nullable();
            $table->string('lipr_transaction_id', 100)->nullable();
            // the 'new' boolean field
            $table->boolean('new')->default(1)->nullable();
            $table->timestamps(); // created_at & updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_bids');
    }
};
