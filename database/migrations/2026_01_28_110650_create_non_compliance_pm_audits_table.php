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
        Schema::create('non_compliance_pm_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('non_compliance_id')->constrained('milestone_non_compliances')->cascadeOnDelete();
            $table->foreignId('pm_id')->nullable()->constrained('users');

            $table->enum('audit_result', [
                'continue',
                'restructure',
                'refund',
                'blacklist'
            ]);

            $table->text('findings');
            $table->json('documents')->nullable(); // receipts, videos, logs

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('non_compliance_pm_audits');
    }
};
