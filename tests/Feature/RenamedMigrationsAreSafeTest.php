<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('lets the renamed migrations run again on a database that already has their tables and columns', function () {
    // RefreshDatabase has already run every migration once. Running those four files again is what a live database
    // does when it ran them under their old names: it must change nothing and fail on nothing.
    foreach (['2026_11_11_000001_add_ai_review_to_program_rounds', '2026_11_11_000002_create_application_ai_tables', '2026_11_11_000003_create_ai_review_audit_logs_table', '2026_11_12_000000_create_program_metadata_table'] as $name) {
        $migration = require database_path("migrations/{$name}.php");
        $migration->up();
    }

    expect(Schema::hasTable('application_ai_reviews'))->toBeTrue()
        ->and(Schema::hasColumn('program_rounds', 'review_method'))->toBeTrue()
        ->and(Schema::hasTable('ai_review_audit_logs'))->toBeTrue()
        ->and(Schema::hasTable('program_metadata'))->toBeTrue();
});
