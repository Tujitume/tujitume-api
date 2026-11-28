<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Demo requests become a reviewed pipeline (landing-page form → admin review → 7-day trial account, a proposal
 * email, or a rejection with a reason), and program-provider sign-up becomes invite only.
 *
 *  - demo_requests: request_type / type / status were enums whose values no longer fit (no approved or rejected,
 *    no proposal / pilot / info); they become plain strings, and the review, proposal and trial columns are added.
 *  - program_registration_invites: one-time links to /auth/create/program, each for one email address.
 *  - organizations.is_trial / trial_expires_at: the organization of a 7-day trial account.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->widenDemoRequestColumns();

        Schema::table('demo_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('demo_requests', 'program_industry_id')) {
                $table->unsignedBigInteger('program_industry_id')->nullable()->after('type');
            }
            if (! Schema::hasColumn('demo_requests', 'timezone')) {
                // The requester's device zone, so their emails state the trial end on their own clock
                $table->string('timezone', 64)->nullable()->after('notes');
            }
            if (! Schema::hasColumn('demo_requests', 'reviewed_by_admin_id')) {
                $table->unsignedBigInteger('reviewed_by_admin_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->text('admin_notes')->nullable();

                $table->string('proposal_subject', 255)->nullable();
                $table->longText('proposal_body')->nullable();
                $table->timestamp('proposal_sent_at')->nullable();

                $table->unsignedBigInteger('trial_user_id')->nullable()->index();
                $table->unsignedBigInteger('trial_organization_id')->nullable();
                $table->timestamp('trial_started_at')->nullable();
                $table->timestamp('trial_expires_at')->nullable()->index();
                $table->timestamp('trial_reminder_sent_at')->nullable();
                $table->timestamp('trial_closed_at')->nullable();

                $table->timestamp('full_access_requested_at')->nullable();
                $table->unsignedBigInteger('converted_user_id')->nullable();
                $table->timestamp('converted_at')->nullable();
                $table->timestamp('workspace_ready_sent_at')->nullable();

                $table->index(['email', 'status']);
            }
        });

        if (! Schema::hasTable('program_registration_invites')) {
            Schema::create('program_registration_invites', function (Blueprint $table) {
                $table->id();
                $table->string('email', 191)->index();
                $table->string('name', 150)->nullable();
                $table->string('token_hash', 64)->unique();
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedBigInteger('registered_user_id')->nullable();
                $table->unsignedBigInteger('demo_request_id')->nullable()->index();
                $table->unsignedBigInteger('created_by_admin_id')->nullable();
                // The "your workspace is ready" email goes once, after the new account's first paid subscription
                $table->timestamp('workspace_ready_sent_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'is_trial')) {
                $table->boolean('is_trial')->default(false);
                $table->timestamp('trial_expires_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasColumn('organizations', 'is_trial')) {
                $table->dropColumn(['is_trial', 'trial_expires_at']);
            }
        });

        Schema::dropIfExists('program_registration_invites');

        Schema::table('demo_requests', function (Blueprint $table) {
            if (Schema::hasColumn('demo_requests', 'reviewed_by_admin_id')) {
                $table->dropIndex(['email', 'status']);
                $table->dropColumn([
                    'program_industry_id', 'timezone', 'reviewed_by_admin_id', 'reviewed_at', 'rejection_reason',
                    'admin_notes', 'proposal_subject', 'proposal_body', 'proposal_sent_at', 'trial_user_id',
                    'trial_organization_id', 'trial_started_at', 'trial_expires_at', 'trial_reminder_sent_at',
                    'trial_closed_at', 'full_access_requested_at', 'converted_user_id', 'converted_at',
                    'workspace_ready_sent_at',
                ]);
            }
        });
    }

    private function widenDemoRequestColumns(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE demo_requests MODIFY request_type VARCHAR(30) NOT NULL DEFAULT 'demo'");
            DB::statement('ALTER TABLE demo_requests MODIFY type VARCHAR(30) NULL DEFAULT NULL');
            DB::statement("ALTER TABLE demo_requests MODIFY status VARCHAR(30) NOT NULL DEFAULT 'pending'");

            return;
        }

        // SQLite (tests) keeps enum values as CHECK constraints that cannot be altered: rebuild the table.
        $rows = DB::table('demo_requests')->get()->map(fn ($row) => (array) $row)->all();
        Schema::drop('demo_requests');
        Schema::create('demo_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('org', 255);
            $table->string('email', 191);
            $table->string('request_type', 30)->default('demo');
            $table->string('type', 30)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamps();
        });
        foreach ($rows as $row) {
            DB::table('demo_requests')->insert($row);
        }
    }
};
