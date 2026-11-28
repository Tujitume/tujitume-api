<?php

use App\Models\Auth\User;
use App\Models\Programs\Rounds\RoundReviewer;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// ISSUE-1 (e2e test 2026-11-27): GET programs/{program}/rounds showed a draft program's rounds, their review set-up and
// every applicant in them to any signed-in user.
it('lists rounds only for the program team and its Business Coaches', function () {
    $url = "/api/v1/programs/{$this->program->id}/rounds";

    $this->actingAsOrgOwner()->getJson($url)->assertOk();
    $this->actingAsApplicant()->getJson($url)->assertForbidden();
    $this->actingAs(User::factory()->create(['user_type_id' => 1]), 'sanctum')->getJson($url)->assertForbidden();

    RoundReviewer::create(['round_id' => $this->round->id, 'user_id' => $this->reviewerUser->id]);
    $this->actingAsReviewer()->getJson($url)->assertOk();
});
