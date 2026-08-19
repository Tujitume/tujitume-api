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
        Schema::create('demo_requests', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);
            $table->string('org', 255);
            $table->string('email', 191);

            $table->enum('request_type', [
                'demo',
                'meeting',
                'trial',
                'consultation',
            ])->default('demo');

            $table->enum('type', [
                'program',
                'investment',
                'capital',
                'service',
                'platform',
            ])->default('platform');

            $table->text('notes')->nullable();

            $table->enum('status', [
                'pending',
                'contacted',
                'scheduled',
                'completed',
                'cancelled',
            ])->default('pending');

            $table->unsignedBigInteger('handled_by')->nullable();

            $table->timestamps();

            $table->foreign('handled_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demo_requests');
    }
};
