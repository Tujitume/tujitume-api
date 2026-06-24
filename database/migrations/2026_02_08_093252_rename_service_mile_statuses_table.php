<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::rename('service_mile_statuses', 'service_booking_milestones');
    }

    public function down()
    {
        Schema::rename('service_booking_milestones', 'service_mile_statuses');
    }
};
