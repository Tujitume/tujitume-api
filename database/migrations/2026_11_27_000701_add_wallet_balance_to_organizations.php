<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DECISION-6 (owner, 27 Nov): the organization has its own wallet (organizations.lipr_wallet, its own LIPR account) with a
 * KES balance kept here: deposits add to it, moving money to a program takes it out (so set-aside money cannot be
 * withdrawn), money moved back from a program returns to it, withdrawals take from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('organizations', 'wallet_balance')) return;
        Schema::table('organizations', function (Blueprint $table) {
            $table->decimal('wallet_balance', 15, 2)->default(0)->after('lipr_wallet');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('organizations', 'wallet_balance')) return;
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('wallet_balance'));
    }
};
