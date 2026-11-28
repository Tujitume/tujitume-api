<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When the "your plan ends soon" email went out for a subscription (subscriptions:remind-ending), so it is sent once
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('business_subscriptions', 'ending_reminder_sent_at')) {
            Schema::table('business_subscriptions', function (Blueprint $table) {
                $table->timestamp('ending_reminder_sent_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('business_subscriptions', 'ending_reminder_sent_at')) {
            Schema::table('business_subscriptions', function (Blueprint $table) {
                $table->dropColumn('ending_reminder_sent_at');
            });
        }
    }
};
