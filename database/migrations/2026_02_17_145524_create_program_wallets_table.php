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
        Schema::create('program_wallets', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('program_id')->unique();           // one wallet per program
            $table->unsignedBigInteger('application_id')->nullable();   // optional: per-application sub-wallet

            $table->decimal('total_deposited', 15, 2)->default(0.00);
            $table->decimal('total_disbursed', 15, 2)->default(0.00);
            $table->decimal('total_reserved', 15, 2)->default(0.00);    // reserved for approved milestones
            $table->decimal('balance', 15, 2)->default(0.00);
            $table->string('lipr_wallet', 200)->nullable();


            $table->char('currency', 3)->default('KES');

            $table->enum('status', [
                'inactive',     // program not yet awarded
                'active',       // funds loaded, disbursements can happen
                'frozen',       // under audit
                'closed',       // program concluded
            ])->default('inactive');

            $table->text('notes')->nullable();

            $table->foreign('program_id')->references('id')->on('programs')->onDelete('restrict');
            $table->foreign('application_id')
                ->references('id')->on('program_applications')->onDelete('set null');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('program_wallets');
    }
};
