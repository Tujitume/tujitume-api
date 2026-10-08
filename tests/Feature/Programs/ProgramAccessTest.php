<?php

use App\Models\Auth\User;
use App\Models\Communication\Notifications;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Opening someone else\'s program records', function () {
    it('lets the applicant, the program owner and the assigned reviewer open the application', function () {
        $this->actingAsApplicant()->getJson("/api/v1/programs/applications/{$this->application->id}")->assertOk();
        $this->actingAsOrgOwner()->getJson("/api/v1/programs/applications/{$this->application->id}")->assertOk();

        $this->application->update(['assigned_reviewer_id' => $this->reviewerUser->id]);
        $this->actingAsReviewer()->getJson("/api/v1/programs/applications/{$this->application->id}")->assertOk();
    });

    it('answers 403 forbidden to another applicant', function () {
        $stranger = User::factory()->create(['user_type_id' => 1]);
        $this->createVerifiedKyc($stranger, 'entrepreneur');

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/programs/applications/{$this->application->id}")
            ->assertForbidden()
            ->assertJson(['success' => false, 'code' => 'forbidden']);
    });

    it('answers 403 forbidden to another program organisation on every guarded record', function () {
        $otherOrg = User::factory()->create(['user_type_id' => 4]);
        $this->createVerifiedKyc($otherOrg, 'organization');
        $this->actingAs($otherOrg, 'sanctum');

        foreach ([
            "applications/{$this->application->id}",
            "dealroom/applications/{$this->application->id}",
            "rounds/applications/{$this->application->id}",
            "rounds/{$this->round->id}/applications",
            "{$this->program->id}/wallets",
        ] as $path) {
            $this->getJson("/api/v1/programs/{$path}")->assertForbidden()->assertJson(['code' => 'forbidden']);
        }
    });

    it('answers 404 not_found for a record that does not exist', function () {
        $this->actingAsOrgOwner()->getJson('/api/v1/programs/applications/999999')
            ->assertNotFound()
            ->assertJson(['code' => 'not_found']);
    });
});

describe('Notifications are paged', function () {
    it('returns ten at a time with the unread total across all pages', function () {
        for ($i = 1; $i <= 25; $i++) {
            Notifications::create([
                'date' => '08 Oct, 10:00 am', 'receiver_id' => $this->orgUser->id, 'customer_id' => $this->orgUser->id,
                'text' => "Notification {$i}", 'link' => 'dashboard.programOrg.account', 'type' => 'program',
            ]);
        }
        Notifications::where('receiver_id', $this->orgUser->id)->update(['new' => 1, 'visible' => 1]);

        $first = $this->actingAsOrgOwner()->getJson('/api/v1/notifications?page=1&per_page=10')->assertOk();
        expect($first->json('data'))->toHaveCount(10);
        expect($first->json('meta.has_more'))->toBeTrue();
        expect($first->json('meta.unread_count'))->toBe(25);

        $last = $this->actingAsOrgOwner()->getJson('/api/v1/notifications?page=3&per_page=10')->assertOk();
        expect($last->json('data'))->toHaveCount(5);
        expect($last->json('meta.has_more'))->toBeFalse();
    });

    it('still returns everything when no page size is asked for', function () {
        Notifications::create([
            'date' => '08 Oct, 10:00 am', 'receiver_id' => $this->orgUser->id, 'customer_id' => $this->orgUser->id,
            'text' => 'One', 'link' => 'dashboard.programOrg.account', 'type' => 'program',
        ]);

        $this->actingAsOrgOwner()->getJson('/api/v1/notifications')->assertOk()->assertJsonMissingPath('meta');
    });
});
