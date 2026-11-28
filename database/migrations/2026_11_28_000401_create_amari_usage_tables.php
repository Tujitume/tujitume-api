<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amari usage, for Admin › Amari usage. Amari is free (it is not billed and not part of ai_usage_logs, which is the
 * paid review AI); these rows let the admin see who uses it, how much, and set daily limits.
 *
 * One row per request through /api/v1/amari/chat. Never the question, the answer or a raw IP address: a visitor is a
 * keyed hash of the browser's guest id (or of the IP when there is none).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('amari_usage_logs')) {
            Schema::create('amari_usage_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('organization_id')->nullable()->index(); // the user's organization when asked
                $table->unsignedTinyInteger('user_type_id')->nullable();
                $table->string('guest_key', 40)->nullable()->index(); // visitors only
                $table->string('profile', 16)->default('portal');   // portal (signed in), public (landing page), debug
                $table->string('feature', 32)->default('chat');     // chat, guide, document_reading, match_score, …
                $table->string('model', 120)->nullable();
                $table->string('status', 16);                       // answered, stopped, limited, paused, blocked, error
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->unsignedInteger('total_tokens')->default(0);
                $table->boolean('tokens_estimated')->default(false); // the provider sent no usage (e.g. the user pressed Stop)
                $table->unsignedInteger('duration_ms')->nullable();
                $table->timestamp('created_at')->nullable()->index();

                $table->index(['user_id', 'created_at']);
                $table->index(['guest_key', 'created_at']);
                $table->index(['status', 'created_at']);
            });
        }

        // A per-account override of the default daily limit, or a pause, set by an admin
        if (! Schema::hasTable('amari_user_limits')) {
            Schema::create('amari_user_limits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->unsignedInteger('daily_limit')->nullable(); // null: the default for signed-in users; 0: no limit
                $table->boolean('paused')->default(false);
                $table->string('note', 500)->nullable();
                $table->unsignedBigInteger('updated_by_admin_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('amari_user_limits');
        Schema::dropIfExists('amari_usage_logs');
    }
};
