<?php

namespace Tests\Feature\Programs;

use App\Http\Controllers\Program\ProgramApplicationController;
use App\Models\Auth\User;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Service\Program\ExpertiseMatcher;
use Illuminate\Support\Facades\DB;

/**
 * "Match Business Coaches by expertise" (program_rounds.match_expertise): off unless the owner turns it on; when on,
 * an application goes to a coach whose expertise fits its sector, and to anyone when nobody fits.
 */
class ExpertiseMatchingTest extends ProgramTestCase
{
    private function answerSector(ProgramApplication $application, string $sector): void
    {
        $question = RoundCustomQuestion::create(['round_id' => $this->round->id, 'question_text' => 'Which sector is your business in?', 'question_type' => 'dropdown', 'options' => ['Agriculture', 'Finance'], 'is_required' => true, 'display_order' => 1]);
        ApplicationRoundResponse::create(['application_id' => $application->id, 'round_id' => $this->round->id, 'question_id' => $question->id, 'response' => $sector]);
    }

    private function coach(array $tags, int $position): User
    {
        $user = User::factory()->create(['user_type_id' => 6]);
        DB::table('round_reviewers')->insert(['round_id' => $this->round->id, 'user_id' => $user->id, 'reviewer_type' => 'external', 'expertise_tags' => json_encode($tags), 'acceptance_status' => 'accepted', 'queue_position' => $position, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    private function autoAssign(ProgramApplication $application): void
    {
        $method = new \ReflectionMethod(ProgramApplicationController::class, 'autoAssignNewApplication');
        $method->setAccessible(true);
        $method->invoke(app(ProgramApplicationController::class), $application, $this->round->fresh());
    }

    public function test_an_application_goes_to_the_coach_whose_expertise_fits_its_sector(): void
    {
        $this->round->update(['assignment_type' => 'external', 'assignment_method' => 'load_balanced', 'match_expertise' => true]);
        $finance = $this->coach(['Finance'], 1);
        $farming = $this->coach(['Agriculture'], 2);
        $this->answerSector($this->application, 'Agriculture');

        $this->autoAssign($this->application);

        $this->assertSame($farming->id, (int) ReviewerApplicationAssignment::where('application_id', $this->application->id)->value('reviewer_id'));
        $this->assertNotSame($finance->id, (int) $this->application->fresh()->assigned_reviewer_id);
    }

    public function test_when_nobody_fits_or_matching_is_off_the_usual_rule_applies(): void
    {
        $first = $this->coach(['Finance'], 1);
        $this->coach(['Healthcare'], 2);
        $this->answerSector($this->application, 'Agriculture');

        // Matching on, but no coach knows agriculture: shared out as usual (the first in the queue, both free)
        $this->round->update(['assignment_type' => 'external', 'assignment_method' => 'load_balanced', 'match_expertise' => true]);
        $this->autoAssign($this->application);
        $this->assertSame($first->id, (int) ReviewerApplicationAssignment::where('application_id', $this->application->id)->value('reviewer_id'));

        // Off by default: a new round does not match
        $this->assertFalse((bool) \App\Models\Programs\Rounds\ProgramRound::factory()->create(['program_id' => $this->program->id, 'round_number' => 2])->fresh()->match_expertise);
    }

    public function test_the_common_words_of_an_area_count_as_that_area(): void
    {
        $matcher = new ExpertiseMatcher();

        $this->assertTrue($matcher->fits(['Agriculture'], ['dairy farming']));
        $this->assertTrue($matcher->fits(['Real Estate'], ['affordable housing']));
        $this->assertTrue($matcher->fits(['Finance'], ['sacco']));
        $this->assertFalse($matcher->fits(['Finance'], ['agribusiness']));
        $this->assertFalse($matcher->fits([], ['agriculture']));
    }
}
