<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('round_reviewers', 'minimum_apps_to_get_paid')) {
            return;
        }

        // Preserve the assignment's existing payment term on its order before removing the duplicate.
        DB::table('reviewer_orders')
            ->whereNull('minimum_apps_to_get_paid')
            ->whereNotNull('round_reviewer_id')
            ->orderBy('id')
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $minimum = DB::table('round_reviewers')
                        ->where('id', $order->round_reviewer_id)
                        ->value('minimum_apps_to_get_paid');

                    if ($minimum !== null) {
                        DB::table('reviewer_orders')
                            ->where('id', $order->id)
                            ->update(['minimum_apps_to_get_paid' => $minimum]);
                    }
                }
            });

        Schema::table('round_reviewers', function (Blueprint $table): void {
            $table->dropColumn('minimum_apps_to_get_paid');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('round_reviewers', 'minimum_apps_to_get_paid')) {
            Schema::table('round_reviewers', function (Blueprint $table): void {
                $table->unsignedInteger('minimum_apps_to_get_paid')->nullable()->after('max_apps_assigned');
            });
        }
    }
};
