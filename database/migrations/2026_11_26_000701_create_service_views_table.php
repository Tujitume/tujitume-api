<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long each person has looked at each service (the Explore Services details panel), one row per person and
 * service. App\Service\Services\ServiceMatcher reads it to put what a person (and their organisation's team)
 * spends time on first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('seconds')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'service_id']);
            $table->index(['organization_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_views');
    }
};
