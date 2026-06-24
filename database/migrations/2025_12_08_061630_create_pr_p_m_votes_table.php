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
        Schema::create('pr_p_m_votes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pm_audit_id');
            $table->unsignedBigInteger('voted_pm_id'); // user.id (one of the candidates)
            $table->unsignedBigInteger('investor_id');
            $table->integer('investor_share')->default(0);
            $table->timestamps();

            $table->unique(['pm_audit_id', 'investor_id']); // each investor votes once

            $table->foreign('pm_audit_id')
                ->references('id')->on('p_m_audits')
                ->cascadeOnDelete();

            $table->foreign('voted_pm_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            $table->foreign('investor_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pr_p_m_votes');
    }
};
