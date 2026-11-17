<?php

use App\Modules\ProgramImport\Http\Controllers\BatchController;
use App\Modules\ProgramImport\Http\Controllers\CatalogController;
use App\Modules\ProgramImport\Http\Controllers\GoogleController;
use App\Modules\ProgramImport\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

/*
| Program Import routes. Registered ONLY by ProgramImportServiceProvider when PROGRAM_IMPORTS_ENABLED=true.
| Prefix: /api/v1/program-imports. Middleware: api, auth:sanctum, extend-token, throttle:api (see the provider).
*/

Route::get('start-points', [CatalogController::class, 'startPoints']);
Route::get('types', [CatalogController::class, 'types']);
Route::get('settings', [\App\Modules\ProgramImport\Http\Controllers\SettingsController::class, 'show']);
Route::put('settings', [\App\Modules\ProgramImport\Http\Controllers\SettingsController::class, 'update']);
Route::get('templates/{name}', [CatalogController::class, 'template']);
Route::post('programs/{program}/invitations', [InvitationController::class, 'store'])->whereNumber('program');

Route::prefix('integrations/google')->group(function () {
    Route::get('/', [GoogleController::class, 'status']);
    Route::post('connect', [GoogleController::class, 'connect']);
    Route::delete('/', [GoogleController::class, 'disconnect']);
});

Route::get('/', [BatchController::class, 'index']);
Route::post('/', [BatchController::class, 'store'])->middleware('throttle:program-imports');
Route::prefix('{batch}')->whereNumber('batch')->group(function () {
    Route::get('/', [BatchController::class, 'show']);
    Route::delete('/', [BatchController::class, 'destroy']);
    Route::post('analyze', [BatchController::class, 'analyze'])->middleware('throttle:program-imports-ai');
    Route::patch('mapping', [BatchController::class, 'mapping']);
    Route::patch('form-spec', [BatchController::class, 'formSpec']);
    Route::post('validate', [BatchController::class, 'validateBatch'])->middleware('throttle:program-imports');
    Route::post('readiness', [BatchController::class, 'readiness']);
    Route::get('rows', [BatchController::class, 'rows']);
    Route::patch('rows/{row}', [BatchController::class, 'updateRow'])->whereNumber('row');
    Route::get('errors', [BatchController::class, 'errors']);
    Route::get('file', [BatchController::class, 'file']);
    Route::post('handled', [BatchController::class, 'handled']);
    Route::post('retry', [BatchController::class, 'retry'])->middleware('throttle:program-imports');
    Route::get('failed-rows', [BatchController::class, 'failedRows']);
    Route::post('queue-ai-review', [BatchController::class, 'queueAiReview'])->middleware('throttle:program-imports-ai');
    Route::post('commit', [BatchController::class, 'commit'])->middleware('throttle:program-imports');
    Route::post('rollback', [BatchController::class, 'rollback']);
});
