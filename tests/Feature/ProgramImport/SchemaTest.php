<?php

use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportLink;
use App\Modules\ProgramImport\ProgramImportServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('creates no module tables while the module is disabled', function () {
    if (filter_var(getenv('PROGRAM_IMPORTS_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
        $this->markTestSkipped('Meaningful only while the module is disabled (the default).');
    }
    expect(config('program_imports.enabled'))->toBeFalse()
        ->and(Schema::hasTable('program_import_batches'))->toBeFalse();
});

it('creates the module tables only when enabled and enforces unique external refs per organization', function () {
    config(['program_imports.enabled' => true]);
    app()->register(ProgramImportServiceProvider::class, true);
    $this->artisan('migrate', ['--path' => 'app/Modules/ProgramImport/database/migrations', '--realpath' => false])->assertExitCode(0);

    foreach (['batches', 'rows', 'records', 'links', 'users'] as $t) {
        expect(Schema::hasTable("program_import_$t"))->toBeTrue();
    }

    $b = ImportBatch::create(['organization_id' => 1, 'user_id' => 1, 'type' => 'review', 'source' => 'file', 'start_point' => 'review', 'options' => ['mode' => 'partial']]);
    expect($b->fresh()->options)->toBe(['mode' => 'partial']);

    ImportLink::create(['organization_id' => 1, 'entity_type' => 'application', 'entity_id' => 5, 'external_ref' => 'a1', 'batch_id' => $b->id]);
    ImportLink::create(['organization_id' => 2, 'entity_type' => 'application', 'entity_id' => 6, 'external_ref' => 'a1', 'batch_id' => $b->id]); // other org: fine
    expect(fn () => ImportLink::create(['organization_id' => 1, 'entity_type' => 'application', 'entity_id' => 7, 'external_ref' => 'a1', 'batch_id' => $b->id]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
