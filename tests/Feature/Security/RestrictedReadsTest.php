<?php

use App\Models\Auth\User;
use App\Models\Programs\Rounds\RoundReviewer;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// The server error log is only for admins (and the developer's own debug panel locally); round history only for the
// program's team, the round's Business Coaches and (per application) the applicant.
describe('Restricted reads', function () {
    it('refuses the error log when signed out', function () {
        $this->getJson('/api/v1/error-logs')->assertUnauthorized();
    });

    it('refuses the error log to an ordinary signed-in user outside local development', function () {
        app()->detectEnvironment(fn () => 'production');
        config(['app.debug_admin_emails' => '']);
        $this->actingAsApplicant()->getJson('/api/v1/error-logs')->assertForbidden();
    });

    it('lets an admin account and an allow-listed email read the error log, not a capital account', function () {
        app()->detectEnvironment(fn () => 'production');
        $admin = User::factory()->create(['user_type_id' => 7]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/error-logs')->assertOk();
        $capital = User::factory()->create(['user_type_id' => 5]);
        $this->actingAs($capital, 'sanctum')->getJson('/api/v1/error-logs')->assertForbidden();

        config(['app.debug_admin_emails' => ' Dev@Example.com , other@example.com']);
        $dev = User::factory()->create(['user_type_id' => 1, 'email' => 'dev@example.com']);
        $this->createVerifiedKyc($dev, 'entrepreneur');
        $this->actingAs($dev, 'sanctum')->getJson('/api/v1/error-logs')->assertOk();
    });

    it('lets any signed-in user read the error log while the API runs locally (debug panel)', function () {
        app()->detectEnvironment(fn () => 'local');
        $this->actingAsApplicant()->getJson('/api/v1/error-logs')->assertOk();
    });

    it('shows a round history to the program team and the round Business Coach only', function () {
        $url = "/api/v1/programs/rounds/{$this->round->id}/rounds-history";
        $this->actingAsOrgOwner()->getJson($url)->assertOk();
        $this->actingAsApplicant()->getJson($url)->assertForbidden();
        $this->actingAsReviewer()->getJson($url)->assertForbidden();

        RoundReviewer::create(['round_id' => $this->round->id, 'user_id' => $this->reviewerUser->id, 'acceptance_status' => 'accepted']);
        $this->actingAsReviewer()->getJson($url)->assertOk();
    });

    it('shows an application history to its applicant and the program team, not to others', function () {
        $url = "/api/v1/programs/applications/{$this->application->id}/rounds-history";
        $this->actingAsApplicant()->getJson($url)->assertOk();
        $this->actingAsOrgOwner()->getJson($url)->assertOk();

        $stranger = User::factory()->create(['user_type_id' => 1]);
        $this->createVerifiedKyc($stranger, 'entrepreneur');
        $this->actingAs($stranger, 'sanctum')->getJson($url)->assertForbidden();
    });
});
