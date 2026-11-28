<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * lipr_payments.amount / amount_usd were double(8,2): at most 999,999.99, so any KES payment above about one million
 * (an investment of about USD 7,700) could not be stored and the bid poll then refused it as "amount does not match".
 * Same size as transactions.gross_amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (tests) does not enforce decimal sizes and cannot change columns without doctrine/dbal
        if (Schema::getConnection()->getDriverName() === 'sqlite') return;

        Schema::table('lipr_payments', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->change();
            $table->decimal('amount_usd', 15, 2)->change();
        });
    }

    public function down(): void
    {
        // Narrowing back could cut stored amounts; leave the wider columns in place.
    }
};
