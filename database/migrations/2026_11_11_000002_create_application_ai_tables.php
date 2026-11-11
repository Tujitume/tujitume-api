<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_ai_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('program_applications')->cascadeOnDelete();
            $table->foreignId('round_id')->constrained('program_rounds')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('mode', 20);                         // ai_assisted | ai_only
            $table->string('status', 20)->default('queued');    // queued|extracting|analyzing|ready|failed|needs_attention|stale
            $table->string('input_hash', 64);
            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('prompt_version', 20)->nullable();
            $table->text('summary')->nullable();
            $table->json('suggested_scores')->nullable();
            $table->decimal('suggested_total', 8, 2)->nullable();
            $table->json('deterministic_checks')->nullable();
            $table->json('missing_items')->nullable();
            $table->json('extraction_notes')->nullable();
            $table->json('quality')->nullable();
            $table->json('usage')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'round_id', 'input_hash'], 'ai_reviews_input_unique');
            $table->index(['round_id', 'status']);
        });

        Schema::create('application_ai_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('application_ai_reviews')->cascadeOnDelete();
            $table->string('author_type', 10)->default('ai');   // ai | human
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dimension', 40);
            $table->string('polarity', 20);                     // strength|risk|gap|inconsistency|info
            $table->string('title', 200);
            $table->text('body');
            $table->string('confidence', 10)->nullable();       // low|medium|high (ai only)
            $table->string('status', 12)->default('open');      // open|confirmed|dismissed|overridden
            $table->string('source_type', 20)->nullable();      // answer|custom_field|document|program
            $table->json('source_ref')->nullable();
            $table->unsignedInteger('page')->nullable();
            $table->string('quote', 300)->nullable();
            $table->boolean('quote_verified')->default(false);
            $table->text('original_body')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reviewer_note')->nullable();
            $table->timestamps();

            $table->index(['review_id', 'dimension']);
        });

        Schema::create('application_document_extractions', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 20);                  // answer_file | round_document
            $table->unsignedBigInteger('source_id');
            $table->string('file_hash', 64);
            $table->string('mime', 120)->nullable();
            $table->string('status', 20)->default('pending');   // pending|ok|ocr_ok|unreadable|too_large|unsupported|excluded
            $table->longText('text_content')->nullable();       // private: never returned by the API
            $table->unsignedInteger('page_count')->default(0);
            $table->json('pages')->nullable();
            $table->string('method', 20)->nullable();           // native_pdf|ocr|local_text
            $table->json('digest')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'file_hash'], 'doc_extractions_unique');
        });

        Schema::create('ai_provider_usage', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('model', 120)->nullable();
            $table->date('date');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('tokens_in')->default(0);
            $table->unsignedBigInteger('tokens_out')->default(0);
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('review_id')->nullable();
            $table->timestamps();

            $table->index(['provider', 'date']);
        });

        Schema::table('application_scores', function (Blueprint $table) {
            $table->string('source', 10)->default('human')->after('reviewer_id');
            $table->foreignId('ai_review_id')->nullable()->constrained('application_ai_reviews')->nullOnDelete();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('ai_data_ack_at')->nullable();
            $table->unsignedBigInteger('ai_data_ack_by')->nullable();
            $table->string('ai_data_ack_provider', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['ai_data_ack_at', 'ai_data_ack_by', 'ai_data_ack_provider']));
        Schema::table('application_scores', function (Blueprint $t) {
            $t->dropConstrainedForeignId('ai_review_id');
            $t->dropColumn('source');
        });
        Schema::dropIfExists('ai_provider_usage');
        Schema::dropIfExists('application_document_extractions');
        Schema::dropIfExists('application_ai_findings');
        Schema::dropIfExists('application_ai_reviews');
    }
};
