<?php

namespace Tests\Feature\Programs;

use App\Models\Programs\ProgramApplication;
use App\Support\MatchTier;

/** Ideal (80+) / Strong (60-79) / Needs revision (under 60): on every application, and a filter on the round's table. */
class MatchTierTest extends ProgramTestCase
{
    public function test_the_tier_follows_the_score(): void
    {
        $this->assertSame('ideal', MatchTier::of(80));
        $this->assertSame('ideal', MatchTier::of(100));
        $this->assertSame('strong', MatchTier::of(79.9));
        $this->assertSame('strong', MatchTier::of('60'));
        $this->assertSame('needs_revision', MatchTier::of(59.9));
        $this->assertSame('needs_revision', MatchTier::of(0));
        $this->assertNull(MatchTier::of(null));
        $this->assertNull(MatchTier::of(''));
        $this->assertSame('Strong Match', MatchTier::label(65));
    }

    public function test_applications_carry_their_tier_and_the_round_table_filters_by_it(): void
    {
        foreach ([92, 85, 70, 41] as $score) {
            ProgramApplication::factory()->create([
                'program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id,
                'current_round_id' => $this->round->id, 'status' => 'pending', 'round_status' => 'submitted', 'match_score' => $score,
            ]);
        }
        $this->assertSame('ideal', ProgramApplication::where('match_score', 92)->first()->toArray()['match_tier']);

        $this->actingAsOrgOwner();
        $url = '/api/v1/programs/rounds/'.$this->round->id.'/applications?';
        $page = $this->getJson($url.http_build_query(['page' => 1, 'per_page' => 10]))->assertOk();
        $facets = collect($page->json('data.facets.match_tier'))->pluck('count', 'value')->all();
        $this->assertSame(2, $facets['ideal']);
        $this->assertSame(1, $facets['strong']);
        $this->assertContains('match_tier', collect($page->json('data.filter_schema'))->pluck('key')->all());

        $ideal = $this->getJson($url.http_build_query(['page' => 1, 'per_page' => 10, 'filters' => ['match_tier' => ['ideal']]]))->json('data');
        $this->assertSame(2, $ideal['pagination']['total']);
    }

    public function test_the_program_applications_table_filters_by_tier_in_the_database(): void
    {
        foreach ([92, 70, 41, null] as $score) {
            ProgramApplication::factory()->create([
                'program_id' => $this->program->id, 'user_id' => $this->applicantUser->id, 'program_owner_id' => $this->orgUser->id,
                'current_round_id' => $this->round->id, 'status' => 'pending', 'round_status' => 'submitted', 'match_score' => $score,
            ]);
        }
        $this->actingAsOrgOwner();
        $url = '/api/v1/programs/'.$this->program->id.'/overview/applications?';

        $page = $this->getJson($url.http_build_query(['page' => 1, 'per_page' => 10]))->assertOk();
        $facets = collect($page->json('data.facets.match_tier'))->pluck('count', 'value')->all();
        $this->assertSame(1, $facets['ideal'] ?? null);
        $this->assertSame(1, $facets['strong'] ?? null);
        $this->assertSame(1, $facets['needs_revision'] ?? null);

        $this->assertSame(1, $this->getJson($url.http_build_query(['page' => 1, 'per_page' => 10, 'filters' => ['match_tier' => ['needs_revision']]]))
            ->assertOk()->json('data.pagination.total'));
    }
}
