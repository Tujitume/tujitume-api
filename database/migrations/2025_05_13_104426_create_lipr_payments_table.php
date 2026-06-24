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
        Schema::create('lipr_payments', function (Blueprint $table) {
            $table->id(); // This is your primary key (auto_increment)
            $table->string('reference_id', 255)->unique();
            $table->string('transaction_id', 255)->unique();
            $table->integer('user_id')->default(null);
            $table->string('status', 100);
            $table->string('purpose', 100);
            $table->float('amount'); //  Correct usage
            $table->float('amount_usd');
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lipr_payments');
    }
};
