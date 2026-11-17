<?php

namespace Tests\Feature\Programs;

use App\Models\Programs\Rounds\RoundCustomQuestion;

/** The owner can see, from the server, whether a round is fully configured and where it is in publishing. */
class RoundReadinessTest extends ProgramTestCase
{
    private function readiness(): array
    {
        $round = $this->actingAsOrgOwner()->getJson('/api/v1/programs/get_program/'.$this->program->id)->assertOk()
            ->json('data.program.rounds.0') ?? $this->actingAsOrgOwner()->getJson('/api/v1/programs/get_program/'.$this->program->id)->json('program.rounds.0');

        return $round['readiness'];
    }

    public function test_a_draft_round_with_nothing_done_lists_what_is_missing(): void
    {
        $this->round->update(['status' => 'draft', 'confirmed_steps' => [], 'open_date' => null, 'close_date' => null]);

        $r = $this->readiness();

        $this->assertFalse($r['configured']);
        $this->assertSame('draft', $r['publish_state']);
        $this->assertFalse($r['can_publish']);
        $this->assertContains('setup', array_column($r['missing'], 'step'));
        $this->assertContains('questions', array_column($r['missing'], 'step'));
        $this->assertSame(4, $r['steps_total']);
    }

    public function test_a_published_round_whose_open_date_is_in_the_future_is_scheduled(): void
    {
        $this->round->update(['status' => 'published', 'open_date' => now()->addDays(10)->toDateString(), 'close_date' => now()->addDays(40)->toDateString()]);

        $r = $this->readiness();

        $this->assertSame('scheduled', $r['publish_state']);
        // the API writes dates in the app's day/month/year format
        $this->assertSame(now()->addDays(10)->format('d/m/Y'), $r['opens_on']);
        // a live round no longer needs steps confirmed, but data still missing (here: no questions) is reported
        $this->assertContains('questions', array_column($r['missing'], 'step'));
    }

    public function test_a_published_round_inside_its_dates_is_open_and_past_its_close_date_is_not(): void
    {
        $this->round->update(['status' => 'published', 'open_date' => now()->subDays(2)->toDateString(), 'close_date' => now()->addDays(5)->toDateString()]);
        $this->assertSame('open', $this->readiness()['publish_state']);

        $this->round->update(['close_date' => now()->subDay()->toDateString()]);
        $this->assertSame('deadline_passed', $this->readiness()['publish_state']);

        $this->round->update(['status' => 'closed']);
        $this->assertSame('closed', $this->readiness()['publish_state']);
    }

    public function test_a_confirmed_draft_round_can_be_published(): void
    {
        $this->round->update([
            'status' => 'draft', 'round_name' => 'Application', 'open_date' => now()->addDays(3)->toDateString(), 'close_date' => now()->addDays(30)->toDateString(),
            'scoring_criteria' => [], 'required_documents' => [], 'assignment_type' => 'owner_only',
            'confirmed_steps' => ['setup', 'questions', 'reviewers', 'notifications'],
        ]);
        RoundCustomQuestion::create(['round_id' => $this->round->id, 'question_text' => 'Tell us about your business', 'question_type' => 'short_answer', 'is_required' => true, 'display_order' => 1]);
        foreach (['round.advanced', 'round.not_selected'] as $event) {
            \App\Models\ProgramEmailTemplate::create(['program_id' => $this->program->id, 'event' => $event, 'subject' => 'x', 'body_html' => '<p>x</p>']);
        }

        $r = $this->readiness();

        $this->assertTrue($r['configured'], json_encode($r));
        $this->assertTrue($r['can_publish']);
        $this->assertSame([], $r['missing']);
    }

    public function test_applicants_do_not_get_the_readiness_data(): void
    {
        $response = $this->actingAs($this->applicantUser, 'sanctum')->getJson('/api/v1/programs/get_program/'.$this->program->id)->assertOk();

        $this->assertArrayNotHasKey('readiness', $response->json('program.rounds.0') ?? $response->json('data.program.rounds.0') ?? []);
    }
}
