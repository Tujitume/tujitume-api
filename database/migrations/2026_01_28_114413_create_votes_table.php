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
        Schema::create('votes', function (Blueprint $table) {
            $table->id();

            $table->enum('type', [
                'non_compliance',
                'final_approval',
                'mid_milestone',
                'pre_release',
                'rmep'
            ]);

            $table->unsignedBigInteger('reference_id'); // milestone_id or non_compliance_id

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            $table->enum('status', ['open', 'closed'])->default('open');

            $table->timestamps();

            $table->index(['type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('votes');
    }
};
