<?php

use App\Http\Controllers\Program\Rounds\RoundReviewerController;
use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Communication\Notifications;
use App\Models\Programs\ProgramApplication;
use Illuminate\Support\Facades\DB;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// ISSUE-12 (e2e test 2026-11-27): applications routed to a Business Coach automatically (on submit, or when a coach
// accepts and the waiting ones are shared out) never told the coach. The owner's manual assignment always did.

function noticeCoach($test, int $position): User
{
    $coach = User::factory()->create(['user_type_id' => 6]);
    DB::table('round_reviewers')->insert(['round_id' => $test->round->id, 'user_id' => $coach->id, 'reviewer_type' => 'external',
        'acceptance_status' => 'accepted', 'queue_position' => $position, 'created_at' => now(), 'updated_at' => now()]);

    return $coach;
}

function assignedNotices(User $coach): int
{
    return Notifications::where('receiver_id', $coach->id)
        ->where(fn ($q) => $q->where('text', 'like', '%assigned to you%')->orWhere('text', 'like', '%You now have%'))->count();
}

it('tells the coach a new application was assigned on submit', function () {
    $this->program->update(['application_start_date' => now()->subDay()->toDateString(), 'application_deadline' => now()->addDays(20)->toDateString(), 'status' => 'published', 'visible' => 1]);
    $this->round->update(['status' => 'published', 'assignment_type' => 'external', 'assignment_method' => 'load_balanced']);
    ProgramApplication::whereKey($this->application->id)->delete();
    $coach = noticeCoach($this, 1);
    $business = Listing::create(['user_id' => $this->applicantUser->id, 'name' => 'Shamba Fresh']);

    $this->actingAs($this->applicantUser, 'sanctum')->postJson("/api/v1/programs/{$this->program->id}/applications", [
        'business_id' => $business->id, 'startup_name' => 'Shamba Fresh', 'contact_person_name' => 'Amina',
        'contact_person_email' => 'amina@example.test', 'sector' => 'Agriculture', 'headquarters_location' => 'Nakuru',
        'total_amount_requested' => 5000, 'current_round_id' => $this->round->id, 'round_answers' => [],
    ])->assertCreated();

    expect(assignedNotices($coach))->toBe(1);
});

it('tells coaches only about applications newly theirs when the waiting ones are shared out', function () {
    $this->round->update(['assignment_type' => 'external', 'assignment_method' => 'round_robin']);
    $this->application->update(['round_status' => 'submitted']);
    $coach = noticeCoach($this, 1);

    $controller = app(RoundReviewerController::class);

    $round = $this->round->fresh();
    (function () use ($round, $coach) { $this->assignApplicationsToReviewer($round, $coach->id); $this->sendCoachAssignmentNotices(); })->call($controller);
    expect(assignedNotices($coach))->toBe(1);

    // Nothing new on a second pass: no second notice
    (function () use ($round, $coach) { $this->assignApplicationsToReviewer($round, $coach->id); $this->sendCoachAssignmentNotices(); })->call($controller);
    expect(assignedNotices($coach))->toBe(1);
});

// ISSUE-26: the first coach to accept got every waiting application (and was told so); as more coaches accept, load
// balancing re-deals them, and each coach whose list changed is told how many they have now
it('tells each coach their current number after a load-balanced re-deal (ISSUE-26)', function () {
    $this->round->update(['assignment_type' => 'external', 'assignment_method' => 'load_balanced']);
    $apps = collect([$this->application])->concat(ProgramApplication::factory()->count(3)->create([
        'program_id' => $this->program->id, 'current_round_id' => $this->round->id, 'program_owner_id' => $this->orgUser->id,
    ]));
    $apps->each->update(['round_status' => 'submitted']);
    $first = noticeCoach($this, 1);
    $controller = app(RoundReviewerController::class);
    $round = $this->round->fresh();
    $run = fn ($coach) => (function () use ($round, $coach) { $this->assignApplicationsToReviewer($round, $coach->id); $this->sendCoachAssignmentNotices(); })->call($controller);

    $run($first);
    expect(Notifications::where('receiver_id', $first->id)->latest('id')->value('text'))->toContain('You now have 4');

    $second = noticeCoach($this, 2);
    $run($second);
    expect(Notifications::where('receiver_id', $first->id)->latest('id')->value('text'))->toContain('You now have 2')
        ->and(Notifications::where('receiver_id', $second->id)->latest('id')->value('text'))->toContain('You now have 2');
});
