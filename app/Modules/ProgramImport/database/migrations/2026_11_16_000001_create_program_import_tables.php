<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_import_batches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->index();
            $t->unsignedBigInteger('program_id')->nullable()->index();
            $t->unsignedBigInteger('user_id');
            $t->string('start_point', 30)->nullable();
            $t->string('type', 40);
            $t->string('source', 30);
            $t->string('status', 30)->default('uploaded')->index();
            $t->string('original_filename')->nullable();
            $t->string('file_path')->nullable();
            $t->string('file_hash', 64)->nullable();
            $t->json('mapping')->nullable();
            $t->json('form_spec')->nullable();
            $t->json('options')->nullable();
            $t->json('readiness')->nullable();
            $t->json('landing_preview')->nullable();
            $t->json('stats')->nullable();
            $t->json('ai_usage')->nullable();
            $t->json('error_summary')->nullable();
            $t->timestamp('consent_confirmed_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('rolled_back_at')->nullable();
            $t->timestamps();
        });

        Schema::create('program_import_rows', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('batch_id');
            $t->string('sheet', 40);
            $t->unsignedInteger('row_number');
            $t->json('raw')->nullable();
            $t->json('normalized')->nullable();
            $t->string('status', 20)->default('pending');
            $t->json('errors')->nullable();
            $t->json('warnings')->nullable();
            $t->string('external_ref', 191)->nullable();
            $t->string('target_type', 60)->nullable();
            $t->unsignedBigInteger('target_id')->nullable();
            $t->timestamps();
            $t->index(['batch_id', 'status']);
            $t->index(['batch_id', 'sheet']);
            $t->foreign('batch_id')->references('id')->on('program_import_batches')->cascadeOnDelete();
        });

        Schema::create('program_import_records', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('batch_id');
            $t->string('record_type', 80);
            $t->unsignedBigInteger('record_id');
            $t->string('action', 10); // created|updated|linked
            $t->json('before')->nullable();
            $t->timestamps();
            $t->index(['batch_id', 'record_type']);
            $t->foreign('batch_id')->references('id')->on('program_import_batches')->cascadeOnDelete();
        });

        Schema::create('program_import_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id');
            $t->string('entity_type', 80);
            $t->unsignedBigInteger('entity_id');
            $t->string('external_ref', 191);
            $t->unsignedBigInteger('batch_id');
            $t->timestamp('created_at')->nullable();
            $t->unique(['organization_id', 'entity_type', 'external_ref'], 'pi_links_unique_ref');
            $t->index(['entity_type', 'entity_id']);
            $t->foreign('batch_id')->references('id')->on('program_import_batches')->cascadeOnDelete();
        });

        Schema::create('program_import_users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('batch_id');
            $t->string('kind', 12); // shadow|placeholder
            $t->timestamp('claimed_at')->nullable();
            $t->timestamps();
            $t->foreign('batch_id')->references('id')->on('program_import_batches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['users', 'links', 'records', 'rows', 'batches'] as $t) {
            Schema::dropIfExists('program_import_'.$t);
        }
    }
};
