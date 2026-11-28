<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paying for a booked service and releasing its milestones (App\Service\Services\ServiceBookingFlow):
 * - a booking's milestones get the deadline the provider's Accept already writes (the column was missing, so accepting
 *   failed), when and how each one was released, and the customer's note when they ask for changes;
 * - a booking keeps the payment reference and time it was paid with, so one payment can never pay two bookings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_booking_milestones', function (Blueprint $table) {
            if (! Schema::hasColumn('service_booking_milestones', 'deadline')) $table->timestamp('deadline')->nullable();
            if (! Schema::hasColumn('service_booking_milestones', 'released_at')) $table->timestamp('released_at')->nullable();
            if (! Schema::hasColumn('service_booking_milestones', 'release_reference')) $table->string('release_reference')->nullable();
            if (! Schema::hasColumn('service_booking_milestones', 'release_status')) $table->string('release_status', 30)->nullable();
            if (! Schema::hasColumn('service_booking_milestones', 'change_note')) $table->text('change_note')->nullable();
        });

        Schema::table('service_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('service_bookings', 'payment_reference')) $table->string('payment_reference')->nullable()->unique();
            if (! Schema::hasColumn('service_bookings', 'paid_at')) $table->timestamp('paid_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_bookings', function (Blueprint $table) {
            $table->dropUnique(['payment_reference']);
            $table->dropColumn(['payment_reference', 'paid_at']);
        });
        Schema::table('service_booking_milestones', function (Blueprint $table) {
            $table->dropColumn(['deadline', 'released_at', 'release_reference', 'release_status', 'change_note']);
        });
    }
};
