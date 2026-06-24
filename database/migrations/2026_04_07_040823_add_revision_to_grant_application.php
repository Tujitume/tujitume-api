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
        Schema::table('grant_applications', function (Blueprint $table) {
            // Track who requested revision
            $table->enum('revision_requested_by', ['grant_owner', 'applicant'])
                ->nullable()
                ->after('funding_setup_status');

            // Revision notes from reviewer
            $table->text('revision_notes')
                ->nullable()
                ->after('revision_requested_by');

            // Optional checklist of items to fix
            $table->json('revision_checklist')
                ->nullable()
                ->after('revision_notes');

            // When revision was requested
            $table->timestamp('revision_requested_at')
                ->nullable()
                ->after('revision_checklist');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grant_applications', function (Blueprint $table) {
            $table->dropColumn([
                'revision_requested_by',
                'revision_notes',
                'revision_checklist',
                'revision_requested_at'
            ]);
        });
    }
};
