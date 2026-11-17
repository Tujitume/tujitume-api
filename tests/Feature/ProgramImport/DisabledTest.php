<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('has no import routes, tables or behavior when the module is disabled', function () {
    if (filter_var(getenv('PROGRAM_IMPORTS_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
        $this->markTestSkipped('Meaningful only while the module is disabled (the default).');
    }
    config(['program_imports.enabled' => false]);
    $u = \App\Models\Auth\User::factory()->create(['user_type_id' => 4]);
    // the reads the page makes on load answer "not turned on" (no 404); nothing else of the module exists
    foreach (['', '/types', '/start-points', '/settings'] as $read) {
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/program-imports'.$read)->assertOk()->assertJsonPath('data.enabled', false);
    }
    $this->actingAs($u, 'sanctum')->getJson('/api/v1/program-imports/1')->assertNotFound();
    $this->actingAs($u, 'sanctum')->postJson('/api/v1/program-imports', [])->assertStatus(405);
    expect(\Illuminate\Support\Facades\Route::has('program-imports.index'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Schema::hasTable('program_import_batches'))->toBeFalse()
        ->and(\App\Modules\ProgramImport\Silent\OutboundGuard::shouldSuppress($u))->toBeFalse()
        ->and(\App\Modules\ProgramImport\Silent\OutboundGuard::shouldSuppress($u->email))->toBeFalse();
    // core messaging works exactly as before
    (new \App\Service\Notification\ProgramNotificationService)->send('round.advanced', [$u], ['program_title' => 'X', 'round_name' => 'R']);
    expect(\App\Models\Communication\Notifications::where('receiver_id', $u->id)->count())->toBe(1);
});
