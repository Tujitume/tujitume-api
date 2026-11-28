<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accepted_bids.lipr_transaction_id was an unsigned int, but LIPR references are text, so every 75% payment saved 0 and
 * the payment could not be matched again. Same type as business_bids.lipr_transaction_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') return; // SQLite does not enforce the type

        Schema::table('accepted_bids', function (Blueprint $table) {
            $table->string('lipr_transaction_id', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Text references would not fit back into an integer column; leave it as text.
    }
};
