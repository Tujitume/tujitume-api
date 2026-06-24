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
        Schema::create('device_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('user_device_id');
            $table->foreign('user_device_id')
                ->references('device_uuid')
                ->on('user_devices')
                ->cascadeOnDelete();
            $table->string('code', 10); // 6-digit or TOTP-like
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'user_device_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_verifications', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['user_device_id']);
            $table->dropIndex(['user_id', 'user_device_id']);
        });

        Schema::dropIfExists('device_verifications');
    }
};
