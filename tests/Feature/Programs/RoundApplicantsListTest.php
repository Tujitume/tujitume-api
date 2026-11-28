<?php

namespace Tests\Feature\Programs;

use App\Models\ApplicationRoundHistory;
use App\Models\Auth\User;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundReviewer;

/** A round's applicants table, and the program's all-rounds applications table, page / search / filter / export on the server. */
class RoundApplicantsListTest extends ProgramTestCase
{
    private function applicants(array $query = [], ?int $roundId = null)
    {
        return $this->getJson('/api/v1/programs/rounds/'.($roundId ?? $this->round->id).'/applications'.($query ? '?'.http_build_query($query) : ''));
    }

    /** 24 more submitted applicants in round 1 (25 with the one from setUp); 6 of them scored, 4 rejected. */
    private function fillRound(): void
    {
        $apps = ProgramApplication::factory()->count(24)->create([
            'program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id,
            'current_round_id' => $this->round->id, 'status' => 'pending', 'round_status' => 'submitted', 'sector' => 'Agriculture',
        ]);
        $apps->take(6)->each(fn ($a) => ApplicationScore::factory()->create(['application_id' => $a->id, 'round_id' => $this->round->id, 'reviewer_id' => $this->reviewerUser->id, 'total_score' => 82]));
        $apps->slice(6, 4)->each(fn ($a) => $a->update(['round_status' => 'not_selected']));
        $apps->first()->update(['startup_name' => 'Zawadi Greenhouses']);
    }

    public function test_without_list_parameters_the_whole_round_comes_back_as_before(): void
    {
        $this->fillRound();

        $res = $this->actingAsOrgOwner()->applicants()->assertOk();
        $this->assertCount(25, $res->json('applications'));
        $this->assertSame(25, $res->json('stats.total'));
        $this->assertNull($res->json('data'));
    }

    public function test_the_round_table_pages_filters_searches_and_exports_on_the_server(): void
    {
        $this->fillRound();
        $this->actingAsOrgOwner();

        $page = $this->applicants(['page' => 1, 'per_page' => 10])->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(25, $page->json('data.pagination.total'));
        $this->assertSame(3, $page->json('data.pagination.last_page'));
        // the cards' counts are over the whole round, whatever the page
        $this->assertSame(25, $page->json('data.stats.total'));

        $status = collect($page->json('data.facets.status'))->pluck('count', 'value')->all();
        $this->assertEquals(['pending' => 21, 'not_selected' => 4], $status);
        $review = collect($page->json('data.facets.review'))->pluck('count', 'value')->all();
        $this->assertEquals(['scored' => 6, 'not_scored' => 19], $review);

        // a filter
        $rejected = $this->applicants(['page' => 1, 'per_page' => 10, 'filters' => ['status' => ['not_selected']]])->json('data');
        $this->assertSame(4, $rejected['pagination']['total']);
        $this->assertSame(6, $this->applicants(['page' => 1, 'per_page' => 10, 'filters' => ['review' => ['scored']]])->json('data.pagination.total'));
        $this->assertSame(6, $this->applicants(['page' => 1, 'per_page' => 10, 'filters' => ['band' => ['high']]])->json('data.pagination.total'));

        // search
        $found = $this->applicants(['page' => 1, 'per_page' => 10, 'search' => 'zawadi'])->json('data.items');
        $this->assertSame(['Zawadi Greenhouses'], collect($found)->pluck('startup_name')->all());

        // sort by score: the scored ones first when descending
        $top = $this->applicants(['page' => 1, 'per_page' => 6, 'sort' => '-score'])->json('data.items');
        $this->assertSame([82.0], collect($top)->pluck('review_summary.average_score')->map(fn ($v) => (float) $v)->unique()->values()->all());

        // export: every matching row, more than a page, following the filters
        $all = $this->applicants(['export' => 1, 'per_page' => 10])->json('data');
        $this->assertCount(25, $all['items']);
        $this->assertFalse($all['export_truncated']);
        $this->assertArrayNotHasKey('pagination', $all);
        $this->assertCount(21, $this->applicants(['export' => 1, 'filters' => ['status' => ['pending']]])->json('data.items'));
    }

    public function test_the_round_table_keeps_its_authorization(): void
    {
        $this->fillRound();

        $stranger = User::factory()->create(['user_type_id' => 4]);
        $this->createVerifiedKyc($stranger, 'organization');
        $this->actingAs($stranger, 'sanctum')->applicants(['page' => 1, 'per_page' => 10])->assertForbidden();
        $this->actingAsApplicant()->applicants(['export' => 1])->assertForbidden();

        // a Business Coach of the round may read it
        RoundReviewer::create(['round_id' => $this->round->id, 'user_id' => $this->reviewerUser->id]);
        $this->actingAsReviewer()->applicants(['page' => 1, 'per_page' => 10])->assertOk();
    }

    public function test_the_all_rounds_table_lists_each_applicant_once_in_their_current_round(): void
    {
        $this->fillRound();
        $round2 = ProgramRound::factory()->create(['program_id' => $this->program->id, 'round_number' => 2, 'status' => 'published', 'round_name' => 'Pitch']);
        // three moved on to round 2: they show there, not in round 1
        $moved = ProgramApplication::where('program_id', $this->program->id)->where('round_status', 'submitted')->take(3)->get();
        $moved->each(function ($a) use ($round2) {
            ApplicationRoundHistory::create(['application_id' => $a->id, 'round_id' => $this->round->id, 'round_number' => 1, 'entered_at' => now()->subDays(3), 'submitted_at' => now()->subDays(3), 'exited_at' => now()->subDay(), 'outcome' => 'advanced']);
            $a->update(['current_round_id' => $round2->id, 'round_status' => 'draft']);
        });
        $url = '/api/v1/programs/'.$this->program->id.'/round-applications';
        $this->actingAsOrgOwner();

        $page = $this->getJson($url.'?page=1&per_page=10')->assertOk();
        $this->assertCount(10, $page->json('data.items'));
        $this->assertSame(25, $page->json('data.pagination.total'));
        $this->assertSame(25, $page->json('data.stats.total'));
        $rounds = collect($page->json('data.facets.round'))->pluck('count', 'value')->all();
        $this->assertEquals([$this->round->id => 22, $round2->id => 3], $rounds);

        $inRound2 = $this->getJson($url.'?'.http_build_query(['export' => 1, 'filters' => ['round' => [$round2->id]]]))->json('data.items');
        $this->assertCount(3, $inRound2);
        $this->assertSame('Pitch', $inRound2[0]['in_round']['round_name']);
        $this->assertCount(3, $this->getJson($url.'?'.http_build_query(['export' => 1, 'filters' => ['status' => ['awaiting']]]))->json('data.items'));
        $this->assertCount(4, $this->getJson($url.'?'.http_build_query(['export' => 1, 'filters' => ['status' => ['not_selected']]]))->json('data.items'));
        $this->assertSame(['Zawadi Greenhouses'], collect($this->getJson($url.'?search=zawadi&page=1&per_page=10')->json('data.items'))->pluck('startup_name')->all());

        $all = $this->getJson($url.'?export=1&per_page=10')->json('data');
        $this->assertCount(25, $all['items']);

        // someone outside the program is refused; a Business Coach only sees the rounds they review
        $stranger = User::factory()->create(['user_type_id' => 4]);
        $this->actingAs($stranger, 'sanctum')->getJson($url.'?page=1&per_page=10')->assertForbidden();
        RoundReviewer::create(['round_id' => $round2->id, 'user_id' => $this->reviewerUser->id]);
        $this->assertSame(3, $this->actingAsReviewer()->getJson($url.'?page=1&per_page=10')->json('data.pagination.total'));
    }
}
