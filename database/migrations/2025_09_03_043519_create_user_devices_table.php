<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_uuid')->unique(); // stored as httpOnly cookie on the browser
            $table->string('name')->nullable();    // user-editable label: "Rana’s Edge"
            $table->string('platform')->nullable(); // "Windows", "iOS", "Android"
            $table->string('browser')->nullable();  // "Chrome 139"
            $table->string('ip')->nullable();
            $table->string('location')->nullable(); // optional (city,country) if you add geolocation
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_verified']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
