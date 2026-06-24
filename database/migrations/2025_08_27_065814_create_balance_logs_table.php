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
        Schema::create('balance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->decimal('old_balance', 12, 2)->default(0);
            $table->decimal('new_balance', 12, 2);
            $table->decimal('change_amount', 12, 2);
            $table->decimal('unsettled_amount', 12, 2)->default(0);
            $table->enum('type', ['deposit', 'withdraw']);
            $table->enum('status', ['pending', 'settled', 'failed'])->default('pending');
            $table->string('method')->nullable();
            $table->integer('changed_by')->nullable(); // who triggered
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('balance_logs');
    }
};
