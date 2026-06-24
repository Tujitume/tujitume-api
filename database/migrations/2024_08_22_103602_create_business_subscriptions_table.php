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
        Schema::create('business_subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('plan')->nullable();
            $table->unsignedInteger('investor_id')->nullable();
            $table->unsignedInteger('amount')->nullable();
            $table->unsignedInteger('token_remaining')->nullable();
            $table->string('chosen_range')->nullable();

            // trial and active as tiny integers
            $table->tinyInteger('trial')->nullable();
            $table->string('expire_date')->nullable();
            $table->string('start_date')->nullable();
            $table->tinyInteger('active')->default(1);
            $table->string('stripe_sub_id')->nullable();
            $table->string('lipr_payment_id')->nullable();
            $table->enum('method', ['stripe', 'lipr'])->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_subscriptions');
    }
};
