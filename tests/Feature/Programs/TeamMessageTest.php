<?php

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Communication\Messages;

uses(Tests\Feature\Programs\ProgramTestCase::class);

it('lets an editor reply to the organization owner, and writes as the editor, not as the owner to the owner', function () {
    $editor = User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
    $role = Role::firstOrCreate(['name' => 'editor'], ['access_types' => []]);
    OrganizationUserRole::create(['organization_id' => $this->organization->id, 'user_id' => $editor->id, 'role_id' => $role->id, 'status' => 'active']);

    // The owner writes to the editor, then the editor replies
    $this->actingAsOrgOwner()->postJson('/api/v1/messages', ['msg' => 'Hello Adam', 'to_id' => $editor->id])->assertOk();
    $this->actingAs($editor, 'sanctum')->postJson('/api/v1/messages', ['msg' => 'Hello Owen', 'to_id' => $this->orgUser->id])->assertOk()->assertJson(['message' => 'Message Sent!']);

    $reply = Messages::where('msg', 'Hello Owen')->first();
    expect($reply->from_id)->toBe($editor->id)->and($reply->to_id)->toBe($this->orgUser->id);
});

it('still refuses a real message to yourself', function () {
    $this->actingAsOrgOwner()->postJson('/api/v1/messages', ['msg' => 'Hi me', 'to_id' => $this->orgUser->id])->assertStatus(422);
});

it('gives a team member their own dialog: they write as themselves, and a message to them reaches them, not the owner', function () {
    $editor = User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
    $role = Role::firstOrCreate(['name' => 'editor'], ['access_types' => []]);
    OrganizationUserRole::create(['organization_id' => $this->organization->id, 'user_id' => $editor->id, 'role_id' => $role->id, 'status' => 'active']);

    // The editor writes to an applicant: it comes from the editor, not from the owner
    $this->actingAs($editor, 'sanctum')->postJson('/api/v1/messages', ['msg' => 'From the editor', 'to_id' => $this->applicantUser->id])->assertOk();
    $sent = Messages::where('msg', 'From the editor')->first();
    expect($sent->from_id)->toBe($editor->id)->and($sent->to_id)->toBe($this->applicantUser->id);

    // The applicant writes back to the editor: it goes to the editor, not to the owner
    $this->actingAs($this->applicantUser, 'sanctum')->postJson('/api/v1/messages', ['msg' => 'To the editor', 'to_id' => $editor->id])->assertOk();
    $reply = Messages::where('msg', 'To the editor')->first();
    expect($reply->to_id)->toBe($editor->id)->and($reply->from_id)->toBe($this->applicantUser->id);

    // The owner has a dialog of their own with the same applicant
    $this->actingAsOrgOwner()->postJson('/api/v1/messages', ['msg' => 'From the owner', 'to_id' => $this->applicantUser->id])->assertOk();
    expect(Messages::where('msg', 'From the owner')->value('from_id'))->toBe($this->orgUser->id);
});
