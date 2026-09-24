<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviewer_orders', function (Blueprint $table) {
            $table->foreignId('round_reviewer_id')->nullable()->after('round_id')
                ->constrained('round_reviewers')->nullOnDelete();
            $table->enum('acceptance_status', ['pending', 'accepted', 'declined'])->default('pending')->after('work_status');
            $table->timestamp('accepted_at')->nullable()->after('acceptance_status');
        });

        Schema::table('reviewer_application_assignments', function (Blueprint $table) {
            $table->foreignId('reviewer_order_id')->nullable()->after('reviewer_id')
                ->constrained('reviewer_orders')->nullOnDelete();
            $table->index('reviewer_order_id');
        });

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
        Schema::table('reviewer_application_assignments', function (Blueprint $table) {
            $table->dropForeign(['reviewer_order_id']);
            $table->dropIndex(['reviewer_order_id']);
            $table->dropColumn('reviewer_order_id');
        });
        Schema::table('reviewer_orders', function (Blueprint $table) {
            $table->dropForeign(['round_reviewer_id']);
            $table->dropColumn(['round_reviewer_id', 'acceptance_status', 'accepted_at']);
        });
    }
};
