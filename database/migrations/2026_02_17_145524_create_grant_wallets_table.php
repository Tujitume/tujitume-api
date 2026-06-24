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
        Schema::create('grant_wallets', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('grant_id')->unique();           // one wallet per grant
            $table->unsignedBigInteger('application_id')->nullable();   // optional: per-application sub-wallet

            $table->decimal('total_deposited', 15, 2)->default(0.00);
            $table->decimal('total_disbursed', 15, 2)->default(0.00);
            $table->decimal('total_reserved', 15, 2)->default(0.00);    // reserved for approved milestones
            $table->decimal('balance', 15, 2)->default(0.00);
            $table->string('lipr_wallet', 200)->nullable();


            $table->char('currency', 3)->default('KES');

            $table->enum('status', [
                'inactive',     // grant not yet awarded
                'active',       // funds loaded, disbursements can happen
                'frozen',       // under audit
                'closed',       // grant concluded
            ])->default('inactive');

            $table->text('notes')->nullable();

            $table->foreign('grant_id')->references('id')->on('grants')->onDelete('restrict');
            $table->foreign('application_id')
                ->references('id')->on('grant_applications')->onDelete('set null');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grant_wallets');
    }
};
