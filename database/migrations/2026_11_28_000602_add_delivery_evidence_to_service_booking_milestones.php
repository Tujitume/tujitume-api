<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What the provider delivered when marking a service milestone done (a note and files), for the customer to check. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_booking_milestones', function (Blueprint $table) {
            if (!Schema::hasColumn('service_booking_milestones', 'delivery_note')) $table->text('delivery_note')->nullable();
            if (!Schema::hasColumn('service_booking_milestones', 'delivery_files')) $table->json('delivery_files')->nullable();
            if (!Schema::hasColumn('service_booking_milestones', 'delivered_at')) $table->timestamp('delivered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_booking_milestones', function (Blueprint $table) {
            foreach (['delivery_note', 'delivery_files', 'delivered_at'] as $column) {
                if (Schema::hasColumn('service_booking_milestones', $column)) $table->dropColumn($column);
            }
        });
    }
};
