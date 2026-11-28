<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed what in an organization: one row per create / update / delete an organization member makes, with the
 * old and new value of every field that changed. Rows are never edited; only the owner and super admins read them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organization_audit_logs')) {
            return;
        }

        Schema::create('organization_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('actor_name', 200)->nullable();
            $table->string('actor_role', 40)->nullable();
            $table->string('action', 20);                     // created | updated | deleted
            $table->string('subject_type', 80);               // program, program_round, program_application, ...
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 255)->nullable(); // "Climate-Smart SME Growth Fund 2026"
            $table->json('changes')->nullable();              // { field: { old, new } }
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'subject_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_audit_logs');
    }
};
