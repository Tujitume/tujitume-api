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
        Schema::create('mid_milestone_votes', function (Blueprint $table) {

            $table->id();
            $table->unsignedBigInteger('mid_milestone_id');
            $table->unsignedBigInteger('investor_id');

            // vote: approve, reject, pm_audit
            $table->enum('vote', ['approve', 'reject', 'audit']);

            // Weighted voting (based on investment %)
            $table->decimal('weight', 10, 2)->default(0); // example: 12.50%

            $table->text('reason')->nullable(); // rejection reason, audit reason

            $table->timestamps();

            $table->unique(['mid_milestone_id', 'investor_id']); // each investor votes once
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mid_milestone_votes');
    }
};
