<?php

namespace Tests\Feature\Programs;

use App\Models\ApplicationRoundHistory;
use App\Models\Auth\User;
use App\Models\Programs\Ai\ApplicationAiReview;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundReviewer;
use Illuminate\Support\Collection;

/**
 * The round applicants page without the whole round in the browser: the described filters of its table, the card
 * counts (summary=1), the decide-by-score preview, deciding many at once on the server and the AI tab's joined rows.
 */
class RoundApplicantsPageTest extends ProgramTestCase
{
    private Collection $apps;

    private User $coach2;

    /**
     * Round 1 of a two-round program, 10 applicants (with the one from setUp):
     *   scores 90 (Neema, also scored by Business Coach 1), 75, 50, 20 (Business Coach 1), the rest not scored
     *   two rejected, one awaiting (moved in from nowhere: a round 2 participant is not here)
     */
    private function fillRound(): void
    {
        $this->program->update(['program_type' => 'multi_round', 'total_rounds' => 2]);
        ProgramRound::factory()->create(['program_id' => $this->program->id, 'round_number' => 2, 'status' => 'draft', 'round_name' => 'Pitch']);
        $this->round->update(['review_method' => 'ai_assisted', 'assignment_type' => 'external']);
        $this->coach2 = User::factory()->create(['user_type_id' => 6, 'first_name' => 'Neema', 'last_name' => 'Otieno']);

        $this->application->update(['sector' => 'Retail', 'headquarters_location' => 'Westlands, Nairobi, Kenya', 'total_amount_requested' => 1000, 'created_at' => now()->subDays(40)]);
        $more = ProgramApplication::factory()->count(9)->create([
            'program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id,
            'current_round_id' => $this->round->id, 'status' => 'pending', 'round_status' => 'submitted', 'sector' => 'Agriculture',
            'headquarters_location' => 'Arusha, Tanzania', 'total_amount_requested' => 5000, 'is_eligible_to_advance' => false,
        ]);
        $this->apps = collect([$this->application])->merge($more)->values();

        foreach ([90, 75, 50, 20] as $i => $score) {
            $app = $this->apps[$i];
            ApplicationScore::factory()->create(['application_id' => $app->id, 'round_id' => $this->round->id, 'reviewer_id' => $this->reviewerUser->id, 'total_score' => $score]);
            if ($i > 0) { // one Business Coach per application in a round; the first is Neema's
                \Database\Factories\ReviewerApplicationAssignmentFactory::new()->completed()->create(['round_id' => $this->round->id, 'application_id' => $app->id, 'reviewer_id' => $this->reviewerUser->id]);
            }
        }
        ApplicationScore::factory()->create(['application_id' => $this->apps[0]->id, 'round_id' => $this->round->id, 'reviewer_id' => $this->coach2->id, 'total_score' => 90]);
        \Database\Factories\ReviewerApplicationAssignmentFactory::new()->completed()->create(['round_id' => $this->round->id, 'application_id' => $this->apps[0]->id, 'reviewer_id' => $this->coach2->id]);
        // assigned, not scored yet
        \Database\Factories\ReviewerApplicationAssignmentFactory::new()->create(['round_id' => $this->round->id, 'application_id' => $this->apps[4]->id, 'reviewer_id' => $this->coach2->id]);
        $this->apps[1]->update(['is_eligible_to_advance' => true]);

        $this->apps[5]->update(['round_status' => 'not_selected']);
        $this->apps[6]->update(['round_status' => 'not_selected']);

        ApplicationAiReview::create(['application_id' => $this->apps[0]->id, 'round_id' => $this->round->id, 'program_id' => $this->program->id, 'mode' => 'ai_assisted', 'status' => 'ready', 'suggested_total' => 80, 'input_hash' => 'a']);
        ApplicationAiReview::create(['application_id' => $this->apps[7]->id, 'round_id' => $this->round->id, 'program_id' => $this->program->id, 'mode' => 'ai_assisted', 'status' => 'failed', 'input_hash' => 'b']);
        ApplicationAiReview::create(['application_id' => $this->apps[8]->id, 'round_id' => $this->round->id, 'program_id' => $this->program->id, 'mode' => 'ai_assisted', 'status' => 'queued', 'input_hash' => 'c']);
    }

    private function url(string $suffix = '', array $query = []): string
    {
        return '/api/v1/programs/rounds/'.$this->round->id.'/applications'.$suffix.($query ? '?'.http_build_query($query) : '');
    }

    private function total(array $filters): int
    {
        return $this->getJson($this->url('', ['page' => 1, 'per_page' => 10, 'filters' => $filters]))->json('data.pagination.total');
    }

    public function test_the_table_describes_its_filters_and_each_one_filters(): void
    {
        $this->fillRound();
        $this->actingAsOrgOwner();

        $schema = collect($this->getJson($this->url('', ['page' => 1, 'per_page' => 10]))->assertOk()->json('data.filter_schema'))->keyBy('key');
        $this->assertSame(['status', 'review', 'coach', 'reviewer', 'ai', 'band', 'score', 'knockout', 'sector', 'location', 'requested', 'submitted'], $schema->keys()->all());
        $this->assertSame('options', $schema['status']['type']);
        $this->assertSame(['pending' => 8, 'awaiting' => 0, 'advanced' => 0, 'not_selected' => 2], collect($schema['status']['options'])->pluck('count', 'value')->all());
        // Business Coaches by name, searchable, counted per applicant they review
        $coaches = collect($schema['reviewer']['options'])->keyBy('value');
        $this->assertTrue($schema['reviewer']['searchable']);
        $this->assertSame('Neema Otieno', $coaches[(string) $this->coach2->id]['label']);
        $this->assertSame(2, $coaches[(string) $this->coach2->id]['count']);
        $this->assertSame(3, $coaches[(string) $this->reviewerUser->id]['count']);
        $this->assertSame(['min' => 20, 'max' => 90], array_intersect_key($schema['score'], ['min' => 1, 'max' => 1]));
        $this->assertSame(['min' => 1000, 'max' => 5000], array_intersect_key($schema['requested'], ['min' => 1, 'max' => 1]));
        $this->assertSame('dateRange', $schema['submitted']['type']);
        $this->assertContains('last30Days', $schema['submitted']['presets']);
        $this->assertSame(['Arusha, Tanzania' => 9, 'Nairobi, Kenya' => 1], collect($schema['location']['options'])->pluck('count', 'value')->all());

        // options (old values still work), entity, number, date, place
        $this->assertSame(2, $this->total(['status' => ['not_selected']]));
        $this->assertSame(4, $this->total(['review' => ['scored']]));
        $this->assertSame(2, $this->total(['reviewer' => [$this->coach2->id]]));
        $this->assertSame(5, $this->total(['reviewer' => [$this->coach2->id, $this->reviewerUser->id]]));
        $this->assertSame(1, $this->total(['coach' => ['in_progress']]));
        $this->assertSame(1, $this->total(['ai' => ['attention']]));
        $this->assertSame(2, $this->total(['score' => '50~80']));
        $this->assertSame(3, $this->total(['score' => '50~']));
        $this->assertSame(1, $this->total(['requested' => '~2000']));
        $this->assertSame(1, $this->total(['location' => ['Nairobi, Kenya']]));
        $this->assertSame(9, $this->total(['submitted' => now()->subDays(7)->toDateString().'~']));
        $this->assertSame(1, $this->total(['submitted' => '~'.now()->subDays(30)->toDateString()]));
        // a filter's bounds follow the others
        $score = collect($this->getJson($this->url('', ['page' => 1, 'per_page' => 10, 'filters' => ['reviewer' => [$this->coach2->id]]]))->json('data.filter_schema'))->firstWhere('key', 'score');
        $this->assertSame([90, 90], [$score['min'], $score['max']]);
    }

    public function test_a_score_rule_round_offers_eligibility_and_a_people_only_round_hides_the_ai_and_coach_filters(): void
    {
        $this->fillRound();
        $this->actingAsOrgOwner();

        $this->round->update(['advancement_mode' => 'score_threshold']);
        $schema = collect($this->getJson($this->url('', ['page' => 1, 'per_page' => 10]))->json('data.filter_schema'))->keyBy('key');
        $this->assertSame('boolean', $schema['eligible']['type']);
        $this->assertSame([['value' => 'yes', 'count' => 1], ['value' => 'no', 'count' => 9]], $schema['eligible']['options']);
        $this->assertSame(1, $this->total(['eligible' => 'yes']));
        $this->assertSame(9, $this->total(['eligible' => 'no']));

        $this->round->update(['advancement_mode' => 'manual', 'review_method' => 'manual', 'assignment_type' => 'owner_only']);
        $keys = collect($this->getJson($this->url('', ['page' => 1, 'per_page' => 10]))->json('data.filter_schema'))->pluck('key');
        $this->assertNotContains('ai', $keys);
        $this->assertNotContains('reviewer', $keys);
        $this->assertNotContains('eligible', $keys);
        // still accepted from an old link
        $this->assertSame(1, $this->total(['coach' => ['in_progress']]));
    }

    public function test_summary_gives_the_cards_counts_without_the_applicants(): void
    {
        $this->fillRound();
        // one applicant waiting to hand in (a later round's participant) is counted as awaiting
        $this->actingAsOrgOwner();

        $res = $this->getJson($this->url('', ['summary' => 1]))->assertOk();
        $this->assertNull($res->json('applications'));
        $this->assertNull($res->json('data.items'));
        $stats = $res->json('stats');
        $this->assertSame($stats, $res->json('data.stats'));
        $this->assertSame(10, $stats['total']);
        $this->assertSame(10, $stats['submitted']);
        $this->assertSame(0, $stats['awaiting']);
        $this->assertSame(8, $stats['pending']);
        $this->assertSame(4, $stats['scored']);
        $this->assertSame(0, $stats['advanced']);
        $this->assertSame(2, $stats['not_selected']);
        $this->assertSame(1, $stats['eligible']);
        $this->assertSame(4, $stats['coaches_done']);
        $this->assertSame(1, $stats['coaches_waiting']);
        $this->assertSame(5, $stats['no_coach']);
        $this->assertSame(2, $stats['coaches']);
        $this->assertSame(1, $stats['ai_ready']);
        $this->assertSame(1, $stats['ai_attention']);
        // pending, submitted, with the AI waiting, working or not started (the failed and ready ones are not)
        $this->assertSame(6, $stats['ai_waiting']);
        $this->assertSame(3, $stats['ai_tracked']);

        // the old full answer carries the same counts
        $this->assertSame(8, $this->getJson($this->url())->json('stats.pending'));
        // the same authorization as the round's list
        $stranger = User::factory()->create(['user_type_id' => 4]);
        $this->actingAs($stranger, 'sanctum')->getJson($this->url('', ['summary' => 1]))->assertForbidden();
    }

    public function test_the_score_line_preview_counts_who_a_line_would_decide(): void
    {
        $this->fillRound();
        $this->actingAsOrgOwner();

        $accept = $this->getJson($this->url('/score-line', ['mode' => 'accept', 'score' => 70]))->assertOk()->json('data');
        $this->assertSame(8, $accept['waiting']);
        $this->assertSame(4, $accept['scored']);
        $this->assertSame(4, $accept['unscored']);
        $this->assertSame(2, $accept['count']);
        $this->assertSame([90.0, 75.0], array_map('floatval', array_column($accept['items'], 'score')));

        $reject = $this->getJson($this->url('/score-line', ['mode' => 'reject', 'score' => 50]))->json('data');
        $this->assertSame([20.0, 50.0], array_map('floatval', array_column($reject['items'], 'score')));
        // kept inside 0 to 100; no score: nobody
        $this->assertSame(100.0, (float) $this->getJson($this->url('/score-line', ['mode' => 'accept', 'score' => 500]))->json('data.score'));
        $this->assertSame(0, $this->getJson($this->url('/score-line', ['mode' => 'accept']))->json('data.count'));
        $this->getJson($this->url('/score-line', ['mode' => 'sideways']))->assertStatus(422);

        // pass / fail: criteria passed
        $this->round->update(['rubric_mode' => 'pass_fail', 'scoring_criteria' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c'], ['name' => 'd']]]);
        $pass = $this->getJson($this->url('/score-line', ['mode' => 'accept', 'score' => 3]))->json('data');
        $this->assertTrue($pass['pass_fail']);
        $this->assertSame(4, $pass['top']);
        $this->assertSame(2, $pass['count']); // 90 → 4 passed, 75 → 3 passed

        $stranger = User::factory()->create(['user_type_id' => 4]);
        $this->actingAs($stranger, 'sanctum')->getJson($this->url('/score-line', ['mode' => 'accept', 'score' => 1]))->assertForbidden();
    }

    public function test_deciding_many_is_checked_on_the_server(): void
    {
        $this->fillRound();
        // one applicant of another program is never touched
        $other = ProgramApplication::factory()->create(['program_id' => \App\Models\Programs\Program::factory()->create(['user_id' => $this->orgUser->id])->id, 'user_id' => $this->applicantUser->id, 'status' => 'pending', 'round_status' => 'submitted']);
        $this->actingAsOrgOwner();

        $ids = [$this->apps[0]->id, $this->apps[1]->id, $this->apps[5]->id, $other->id];
        $res = $this->postJson($this->url('/decide'), ['action' => 'advance', 'ids' => $ids])->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$this->apps[0]->id, $this->apps[1]->id], $res['done']);
        $this->assertEquals([['id' => $this->apps[5]->id, 'reason' => 'decided'], ['id' => $other->id, 'reason' => 'not_in_round']], $res['skipped']);
        $this->assertSame([], $res['failed']);
        $this->assertNotSame($this->round->id, (int) $this->apps[0]->fresh()->current_round_id);

        // reject needs a reason; by a score line: everyone still undecided at or below 50
        $this->postJson($this->url('/decide'), ['action' => 'reject', 'score' => 50])->assertStatus(422);
        $res = $this->postJson($this->url('/decide'), ['action' => 'reject', 'score' => 50, 'reason' => 'Below the line'])->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$this->apps[2]->id, $this->apps[3]->id], $res['done']);
        $this->assertSame('not_selected', $this->apps[3]->fresh()->round_status);

        // a score-rule round decides at finalize; a Business Coach of the round cannot decide
        $this->round->update(['advancement_mode' => 'score_threshold']);
        $this->postJson($this->url('/decide'), ['action' => 'advance', 'ids' => [$this->apps[4]->id]])->assertStatus(422);
        $this->round->update(['advancement_mode' => 'manual']);
        RoundReviewer::create(['round_id' => $this->round->id, 'user_id' => $this->reviewerUser->id]);
        $this->actingAsReviewer()->postJson($this->url('/decide'), ['action' => 'advance', 'ids' => [$this->apps[4]->id]])->assertForbidden();
    }

    public function test_deciding_many_does_not_advance_someone_who_has_not_handed_in(): void
    {
        $this->fillRound();
        $round2 = ProgramRound::where('program_id', $this->program->id)->where('round_number', 2)->first();
        $round2->update(['status' => 'published', 'advancement_mode' => 'manual']);
        $app = $this->apps[9];
        ApplicationRoundHistory::create(['application_id' => $app->id, 'round_id' => $this->round->id, 'round_number' => 1, 'entered_at' => now()->subDays(3), 'submitted_at' => now()->subDays(3), 'exited_at' => now()->subDay(), 'outcome' => 'advanced']);
        ApplicationRoundHistory::create(['application_id' => $app->id, 'round_id' => $round2->id, 'round_number' => 2, 'entered_at' => now()->subDay(), 'outcome' => 'in_progress']);
        $app->update(['current_round_id' => $round2->id, 'round_status' => 'draft']);
        $this->actingAsOrgOwner();

        $url = '/api/v1/programs/rounds/'.$round2->id.'/applications/decide';
        $res = $this->postJson($url, ['action' => 'advance', 'ids' => [$app->id]])->assertOk()->json('data');
        $this->assertSame([['id' => $app->id, 'reason' => 'awaiting']], $res['skipped']);
        $this->assertSame(1, $this->getJson('/api/v1/programs/rounds/'.$round2->id.'/applications?summary=1')->json('stats.awaiting'));
    }

    public function test_the_ai_tab_rows_can_carry_their_applicant_and_the_score_summary(): void
    {
        $this->fillRound();
        $this->actingAsOrgOwner();

        $plain = $this->getJson('/api/v1/programs/rounds/'.$this->round->id.'/ai-reviews/items?tab=success&export=1')->assertOk()->json('data');
        $this->assertArrayNotHasKey('app', $plain['items'][0]);
        $this->assertArrayNotHasKey('summary', $plain);

        $data = $this->getJson('/api/v1/programs/rounds/'.$this->round->id.'/ai-reviews/items?tab=success&export=1&with_round=1')->assertOk()->json('data');
        $row = $data['items'][0];
        $this->assertSame($this->apps[0]->id, $row['application_id']);
        $this->assertSame($this->apps[0]->id, $row['app']['id']);
        $this->assertSame('pending', $row['app']['status_key']);
        $this->assertEquals(90, $row['app']['coach_score']);
        $this->assertEquals(80, $data['summary']['ai_average']);
        $this->assertEquals(58.75, $data['summary']['coach_average']);
        $this->assertEquals(10, $data['summary']['average_gap']);
    }
}
