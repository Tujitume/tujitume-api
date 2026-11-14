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
