<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Renamed from 2027_11_06_000003_...: safe to run again on a database that already ran it under the old name.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_review_audit_logs')) {
            return;
        }

        Schema::create('ai_review_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('round_id')->nullable()->index();
            $table->unsignedBigInteger('application_id')->nullable();
            $table->string('action', 60);          // review_method_changed | ai_only_acknowledged | privacy_ack | config_changed | finding_reviewed | rerun
            $table->json('meta')->nullable();      // ids and enums only: never prompts, completions or document text
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_review_audit_logs');
    }
};
