<?php

use App\Models\Programs\ProgramApplication;
use Illuminate\Support\Facades\DB;

uses(Tests\Feature\Programs\ProgramTestCase::class);

it('clears the old "Not specified" placeholders and leaves real answers alone', function () {
    $this->application->update(['sector' => 'Not specified', 'headquarters_location' => ' NOT SPECIFIED ']);
    $other = ProgramApplication::factory()->create([
        'program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id,
        'current_round_id' => $this->round->id, 'sector' => 'Agri', 'headquarters_location' => 'Nairobi',
    ]);

    (include database_path('migrations/2026_11_17_000020_clear_not_specified_placeholders_on_applications.php'))->up();

    expect(DB::table('program_applications')->where('id', $this->application->id)->value('sector'))->toBe('')
        ->and(DB::table('program_applications')->where('id', $this->application->id)->value('headquarters_location'))->toBe('')
        ->and(DB::table('program_applications')->where('id', $other->id)->value('sector'))->toBe('Agri')
        ->and(DB::table('program_applications')->where('id', $other->id)->value('headquarters_location'))->toBe('Nairobi');
});
