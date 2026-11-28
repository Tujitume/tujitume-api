<?php

use App\Models\Business\Listing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Old businesses saved investors_fee as a percentage (5 meaning 5%), but it is charged as a fixed KSh amount to unlock
 * the business, so those investors were charged KSh 5. Fees below the minimum become the default unlock fee.
 * Not undone: the old percentage was never a usable amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('listings')
            ->where('investors_fee', '>', 0)
            ->where('investors_fee', '<', Listing::MIN_INVESTORS_FEE)
            ->update(['investors_fee' => Listing::DEFAULT_INVESTORS_FEE]);
    }

    public function down(): void
    {
    }
};
