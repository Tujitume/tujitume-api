<?php

use App\Models\Programs\Program;
use App\Models\Programs\Rounds\ProgramRound;
use App\Service\Program\RoundConfiguration;
use App\Support\ProgramSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(Tests\Feature\Programs\ProgramTestCase::class);

/** Programs and rounds open and close at an exact moment (UTC), set in the owner's zone. */
afterEach(fn () => Carbon::setTestNow());

function openingIndustry(): int
{
    return DB::table('program_industries')->insertGetId(['name' => 'Agri ' . uniqid(), 'url' => 'agri-' . uniqid(), 'created_at' => now(), 'updated_at' => now()]);
}

describe('Storage', function () {
    it('stores the moments a form sends in UTC and fills the dates in the owner zone', function () {
        Carbon::setTestNow('2026-11-26 06:00:00');

        $response = $this->actingAsOrgOwner()->postJson('/api/v1/programs/create-program', [
            'program_title' => 'Timed program',
            'program_industry_id' => openingIndustry(),
            'total_program_amount' => 100000,
            'funding_per_business' => 10000,
            'program_type' => 'single_round',
            'application_opens_at' => '2026-12-01T08:00:00+03:00',
            'application_closes_at' => '2026-12-01T23:59:00+03:00',
            'timezone' => 'Africa/Nairobi',
        ])->assertOk();

        $program = Program::findOrFail($response->json('data.program.id') ?? $response->json('program.id'));
        expect($program->getRawOriginal('application_opens_at'))->toBe('2026-12-01 05:00:00')
            ->and($program->getRawOriginal('application_closes_at'))->toBe('2026-12-01 20:59:00')
            ->and($program->application_start_date->toDateString())->toBe('2026-12-01')
            ->and($program->application_deadline->toDateString())->toBe('2026-12-01') // same day is fine with times
            ->and($program->timezone)->toBe('Africa/Nairobi');

        // The first round is the application window: it opens and closes with the program
        $round = $program->rounds()->first();
        expect($round->getRawOriginal('opens_at'))->toBe('2026-12-01 05:00:00')
            ->and($round->getRawOriginal('closes_at'))->toBe('2026-12-01 20:59:00');
    });

    it('reads a time without an offset in the owner zone from Settings', function () {
        Carbon::setTestNow('2026-11-26 06:00:00');
        DB::table('user_settings')->updateOrInsert(['user_id' => $this->orgUser->id], ['timezone' => 'Africa/Lagos', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAsOrgOwner()->patchJson("/api/v1/programs/rounds/{$this->round->id}", [
            'opens_at' => '2026-12-02 09:30',
            'closes_at' => '2026-12-10 17:00',
        ])->assertOk();

        $round = $this->round->fresh();
        expect($round->getRawOriginal('opens_at'))->toBe('2026-12-02 08:30:00') // Lagos is UTC+1
            ->and($round->getRawOriginal('closes_at'))->toBe('2026-12-10 16:00:00')
            ->and($round->open_date->toDateString())->toBe('2026-12-02');
    });

    it('refuses a closing moment that is not after the opening one', function () {
        $this->actingAsOrgOwner()->patchJson("/api/v1/programs/rounds/{$this->round->id}", [
            'opens_at' => '2026-12-02T10:00:00+03:00',
            'closes_at' => '2026-12-02T09:00:00+03:00',
        ])->assertStatus(422)->assertJsonValidationErrors('closes_at');
    });

    it('turns a date written without a time into the whole day in the program zone', function () {
        $this->program->update(['timezone' => 'Africa/Nairobi']);
        $this->round->update(['open_date' => '2026-12-01', 'close_date' => '2026-12-20']);

        $round = $this->round->fresh();
        expect($round->getRawOriginal('opens_at'))->toBe('2026-11-30 21:00:00')  // 00:00:00 EAT
            ->and($round->getRawOriginal('closes_at'))->toBe('2026-12-20 20:59:59'); // 23:59:59 EAT
    });

    it('backfills existing rows with the moments the app enforced: 00:00 and 23:59:59 UTC of their dates', function () {
        DB::table('programs')->where('id', $this->program->id)->update([
            'application_start_date' => '2026-12-01', 'application_deadline' => '2026-12-31', 'application_opens_at' => null, 'application_closes_at' => null, 'timezone' => 'Africa/Nairobi',
        ]);
        DB::table('program_rounds')->where('id', $this->round->id)->update(['open_date' => '2026-12-01', 'close_date' => '2026-12-31', 'opens_at' => null, 'closes_at' => null]);

        $migration = require database_path('migrations/2026_11_26_000201_add_opening_and_closing_times_to_programs_and_rounds.php');
        $migration->up(); // safe to run again: the columns exist, only empty moments are filled

        $program = DB::table('programs')->find($this->program->id);
        $round = DB::table('program_rounds')->find($this->round->id);
        expect($program->timezone)->toBe('UTC')
            ->and(substr($program->application_opens_at, 0, 19))->toBe('2026-12-01 00:00:00')
            ->and(substr($program->application_closes_at, 0, 19))->toBe('2026-12-31 23:59:59')
            ->and(substr($round->opens_at, 0, 19))->toBe('2026-12-01 00:00:00')
            ->and(substr($round->closes_at, 0, 19))->toBe('2026-12-31 23:59:59');
    });
});

describe('Open or closed, to the minute', function () {
    beforeEach(function () {
        $this->program->update(['status' => 'published', 'timezone' => 'Africa/Nairobi']);
        $this->round->update(['status' => 'published', 'opens_at' => '2026-12-01T08:00:00+03:00', 'closes_at' => '2026-12-10T17:00:00+03:00']);
        $this->program->setRelation('rounds', collect([$this->round->fresh()]));
    });

    it('is not open one minute before the opening moment, and open one minute after', function () {
        Carbon::setTestNow('2026-12-01 04:59:00'); // 07:59 EAT
        expect($this->program->applicationsClosedReason())->toBe('Applications have not opened yet.')
            ->and(app(RoundConfiguration::class)->publishState($this->round->fresh()))->toBe('scheduled')
            ->and($this->round->fresh()->notOpenReason())->toContain('1 Dec 2026, 08:00 EAT');

        Carbon::setTestNow('2026-12-01 05:01:00'); // 08:01 EAT
        expect($this->program->applicationsClosedReason())->toBeNull()
            ->and(app(RoundConfiguration::class)->publishState($this->round->fresh()))->toBe('open')
            ->and($this->round->fresh()->notOpenReason())->toBeNull();
    });

    it('is open one minute before the closing moment, and closed one minute after', function () {
        Carbon::setTestNow('2026-12-10 13:59:00'); // 16:59 EAT
        expect($this->program->applicationsClosedReason())->toBeNull()
            ->and(app(RoundConfiguration::class)->publishState($this->round->fresh()))->toBe('open');

        Carbon::setTestNow('2026-12-10 14:01:00'); // 17:01 EAT
        expect($this->program->applicationsClosedReason())->toBe('The application deadline has passed.')
            ->and(app(RoundConfiguration::class)->publishState($this->round->fresh()))->toBe('deadline_passed');
    });

    it('keeps accepting applications once the scheduled update marks the program open', function () {
        Carbon::setTestNow('2026-12-01 05:01:00');
        $this->program->update(['application_opens_at' => '2026-12-01T08:00:00+03:00', 'application_closes_at' => '2026-12-10T17:00:00+03:00']);

        $this->artisan('update:program-status')->assertSuccessful();

        $program = $this->program->fresh()->load('rounds');
        expect($program->status)->toBe('open')
            ->and($program->applicationsClosedReason())->toBeNull();
    });

    it('closes the program at its closing moment in the scheduled update, not at the end of the day', function () {
        $this->program->update(['status' => 'open', 'application_opens_at' => '2026-12-01T08:00:00+03:00', 'application_closes_at' => '2026-12-10T17:00:00+03:00']);

        Carbon::setTestNow('2026-12-10 13:59:00');
        $this->artisan('update:program-status')->assertSuccessful();
        expect($this->program->fresh()->status)->toBe('open');

        Carbon::setTestNow('2026-12-10 14:01:00');
        $this->artisan('update:program-status')->assertSuccessful();
        expect($this->program->fresh()->status)->toBe('closed');
    });

    it('lets a later round open only after the previous one has closed, to the minute', function () {
        $this->program->update(['total_rounds' => 3, 'program_type' => 'multi_round']);
        $body = fn (string $opens) => [
            'round_name' => 'Pitch', 'open_date' => '2026-12-10', 'close_date' => '2026-12-20',
            'opens_at' => $opens, 'closes_at' => '2026-12-20T17:00:00+03:00',
            'rubric_mode' => 'weighted', 'advancement_mode' => 'manual',
        ];

        $this->actingAsOrgOwner()->postJson("/api/v1/programs/{$this->program->id}/rounds", $body('2026-12-10T17:00:00+03:00'))
            ->assertStatus(422)->assertJsonValidationErrors('open_date');
        $this->actingAsOrgOwner()->postJson("/api/v1/programs/{$this->program->id}/rounds", $body('2026-12-10T17:01:00+03:00'))
            ->assertCreated();
    });
});

describe('API output', function () {
    it('returns the moments as ISO 8601 with their offset, and keeps the dates for older callers', function () {
        $this->program->update(['status' => 'published', 'visible' => 1, 'timezone' => 'Africa/Nairobi', 'application_opens_at' => '2026-12-01T08:00:00+03:00', 'application_closes_at' => '2026-12-10T17:00:00+03:00']);
        $this->round->update(['opens_at' => '2026-12-01T08:00:00+03:00', 'closes_at' => '2026-12-10T17:00:00+03:00']);

        $response = $this->actingAsApplicant()->getJson('/api/v1/programs/public-programs')->assertOk();
        $programs = $response->json('data.programs') ?? $response->json('programs');
        $program = collect($programs)->firstWhere('id', $this->program->id);

        expect($program['application_opens_at'])->toBe('2026-12-01T05:00:00+00:00')
            ->and($program['application_closes_at'])->toBe('2026-12-10T14:00:00+00:00')
            ->and($program['application_deadline'])->toStartWith('10/12/2026') // the user's date format, as before
            ->and($program['timezone'])->toBe('Africa/Nairobi')
            ->and($program['rounds'][0]['closes_at'])->toBe('2026-12-10T14:00:00+00:00');
    });

    it('gives the round readiness its exact moments', function () {
        $this->round->update(['opens_at' => '2026-12-01T08:00:00+03:00', 'closes_at' => '2026-12-10T17:00:00+03:00']);

        $response = $this->actingAsOrgOwner()->getJson('/api/v1/programs/get_program/' . $this->program->id)->assertOk();
        $round = $response->json('data.program.rounds.0') ?? $response->json('program.rounds.0');

        expect($round['readiness']['opens_at'])->toBe('2026-12-01T05:00:00+00:00')
            ->and($round['readiness']['closes_at'])->toBe('2026-12-10T14:00:00+00:00');
    });

    it('describes a moment in the zone it was set in', function () {
        expect(ProgramSchedule::describe(Carbon::parse('2026-12-10 14:00:00', 'UTC'), 'Africa/Nairobi'))->toBe('10 Dec 2026, 17:00 EAT')
            ->and(ProgramSchedule::timezone('Not/AZone'))->toBe('Africa/Nairobi');
    });
});
