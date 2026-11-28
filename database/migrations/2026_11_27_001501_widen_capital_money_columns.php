<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * capital_offers.available_amount and startup_pitches.total_amount_requested were double(8,2): anything above
 * 999,999.99 was silently cut to that number (a KES 5,000,000 offer showed 999,999.99 available). Same size as the
 * other money columns now. Existing offers get their available amount back from the total minus what was released.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL only: SQLite (tests) has no fixed-size numbers, and ->change() would need doctrine/dbal there
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE capital_offers MODIFY available_amount DECIMAL(15,2) NULL');
            DB::statement('ALTER TABLE startup_pitches MODIFY total_amount_requested DECIMAL(15,2) NULL');
        }

        // Offers that hit the old ceiling: available = total - released (released milestones of their applications)
        foreach (DB::table('capital_offers')->where('available_amount', '>=', 999999.99)->get(['id', 'total_capital_available']) as $offer) {
            $released = (float) DB::table('capital_milestones as m')
                ->join('startup_pitches as p', 'p.id', '=', 'm.app_id')
                ->where('p.capital_id', $offer->id)
                ->where(fn ($q) => $q->where('m.fund_released', 1)->orWhere('m.status', 1))
                ->sum('m.amount');
            DB::table('capital_offers')->where('id', $offer->id)->update(['available_amount' => max(0, (float) $offer->total_capital_available - $released)]);
        }
    }

    public function down(): void
    {
        // Not narrowed back: that would cut money values again
    }
};
