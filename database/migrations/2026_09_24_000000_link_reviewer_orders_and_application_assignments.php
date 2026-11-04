<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Associate existing round orders with their invitation and existing app assignments with their order.
        foreach (DB::table('reviewer_orders')->whereNotNull('round_id')->get() as $order) {
            $roundReviewerId = DB::table('round_reviewers')
                ->where('round_id', $order->round_id)->where('user_id', $order->reviewer_id)->value('id');
            if ($roundReviewerId) {
                DB::table('reviewer_orders')->where('id', $order->id)->update([
                    'round_reviewer_id' => $roundReviewerId,
                    'acceptance_status' => DB::table('round_reviewers')->where('id', $roundReviewerId)->value('acceptance_status'),
                    'accepted_at' => DB::table('round_reviewers')->where('id', $roundReviewerId)->value('accepted_at'),
                ]);
            }
        }

        foreach (DB::table('reviewer_application_assignments')->get() as $assignment) {
            $orderId = DB::table('reviewer_orders')->where('round_id', $assignment->round_id)
                ->where('reviewer_id', $assignment->reviewer_id)->value('id');
            if ($orderId) {
                DB::table('reviewer_application_assignments')->where('id', $assignment->id)
                    ->update(['reviewer_order_id' => $orderId]);
            }
        }
    }

    public function down(): void
    {
        DB::table('reviewer_application_assignments')->update(['reviewer_order_id' => null]);
        DB::table('reviewer_orders')->update([
            'round_reviewer_id' => null,
            'acceptance_status' => 'pending',
            'accepted_at' => null,
        ]);
    }
};
