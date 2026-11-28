<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ISSUE-22 (owner, 27 Nov): the program pays the transfer fee on top of the milestone amount, so the supplier gets the
 * full amount. The fee taken from the program wallet is kept on the disbursement for the receipt and for refunds.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('disbursements', 'fee_amount')) return;
        Schema::table('disbursements', function (Blueprint $table) {
            $table->decimal('fee_amount', 15, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('disbursements', 'fee_amount')) return;
        Schema::table('disbursements', fn (Blueprint $table) => $table->dropColumn('fee_amount'));
    }
};
