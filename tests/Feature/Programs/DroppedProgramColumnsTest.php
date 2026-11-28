<?php

use App\Models\Programs\ProgramMilestone;
use Illuminate\Support\Facades\Schema;

uses(Tests\Feature\Programs\ProgramTestCase::class);

function sqlAskingFor(string $column, callable $request): array
{
    \Illuminate\Support\Facades\DB::flushQueryLog();
    \Illuminate\Support\Facades\DB::enableQueryLog();
    $request();

    return collect(\Illuminate\Support\Facades\DB::getQueryLog())->pluck('query')
        ->filter(fn ($sql) => str_contains($sql, 'from "programs"') && str_contains($sql, $column))->values()->all();
}

// mid_milestone_required moved from the program to each milestone (2026_11_04_000003 drops it from programs). Asking
// for it in a select broke the application page and the release screen on MySQL (e2e 28 Nov). SQLite reads an unknown
// double-quoted column as a string literal and does not fail, so these tests look at the SQL that was sent.
describe('Program pages do not ask for dropped program columns', function () {
    it('has no program-level mid_milestone_required column any more', function () {
        expect(Schema::hasColumn('programs', 'mid_milestone_required'))->toBeFalse();
    });

    it('opens the application for the program owner', function () {
        $sql = sqlAskingFor('mid_milestone_required', fn () => $this->actingAsOrgOwner()
            ->getJson("/api/v1/programs/applications/{$this->application->id}")
            ->assertOk()
            ->assertJsonPath('pitch.program.id', $this->program->id));

        expect($sql)->toBe([]);
    });

    it('loads the disbursement data of a milestone', function () {
        $milestone = ProgramMilestone::create([
            'app_id' => $this->application->id, 'title' => 'Kiosk build', 'amount' => 1000, 'sequence_order' => 1, 'status' => 'pending',
        ]);

        $sql = sqlAskingFor('mid_milestone_required', fn () => $this->actingAsOrgOwner()
            ->getJson("/api/v1/programs/milestones/{$milestone->id}/disbursement-data")
            ->assertOk()
            ->assertJsonPath('pitch.program.id', $this->program->id));

        expect($sql)->toBe([]);
    });
});
