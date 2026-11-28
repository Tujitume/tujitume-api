<?php

use App\Models\Business\Listing;
use App\Models\Communication\Notifications;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\RoundCustomQuestion;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// ISSUE-3 / ISSUE-4 (e2e test 2026-11-27): a knockout "fail" answer was accepted as eligible and sent to a Business
// Coach, and a signed-in applicant never got "Application received" (the code read the web guard, not the token).

beforeEach(function () {
    $this->program->update([
        'application_start_date' => now()->subDay()->toDateString(), 'application_deadline' => now()->addDays(20)->toDateString(),
        'status' => 'published', 'visible' => 1,
    ]);
    $this->round->update(['status' => 'published']);
    $this->ko = RoundCustomQuestion::create([
        'round_id' => $this->round->id, 'question_text' => 'Is your business registered in Kenya?',
        'question_type' => 'knockout_yes_no', 'is_required' => true, 'knockout_fail_value' => 'no', 'display_order' => 1,
    ]);
    $this->business = Listing::create(['user_id' => $this->applicantUser->id, 'name' => 'Shamba Fresh']);
    // the fixture's own application would make this a second one: start clean
    ProgramApplication::whereKey($this->application->id)->delete();
});

function koSubmit($test, string $answer)
{
    return $test->actingAs($test->applicantUser, "sanctum")->postJson("/api/v1/programs/{$test->program->id}/applications", [
        'business_id' => $test->business->id, 'startup_name' => 'Shamba Fresh', 'contact_person_name' => 'Amina',
        'contact_person_email' => 'amina@example.test', 'sector' => 'Agriculture', 'headquarters_location' => 'Nakuru, Kenya',
        'total_amount_requested' => 5000, 'current_round_id' => $test->round->id,
        'round_answers' => [['question_id' => $test->ko->id, 'response' => $answer]],
    ]);
}

it('marks an application with a failing knockout answer as failed', function () {
    $id = koSubmit($this, 'No')->assertCreated()->json('data.id');
    expect(ProgramApplication::find($id)->knockout_status)->toBe('failed')
        ->and(ProgramApplication::find($id)->round_status)->not->toBe('assigned');
});

it('passes a qualifying knockout answer and thanks the signed-in applicant', function () {
    $before = Notifications::where('receiver_id', $this->applicantUser->id)->count();
    $id = koSubmit($this, 'yes')->assertCreated()->json('data.id');
    expect(ProgramApplication::find($id)->knockout_status)->toBe('passed')
        ->and(Notifications::where('receiver_id', $this->applicantUser->id)->count())->toBe($before + 1);
});
