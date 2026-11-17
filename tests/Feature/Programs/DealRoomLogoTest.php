<?php

namespace Tests\Feature\Programs;

use App\Models\Auth\User;
use App\Models\Auth\UserSetting;

/** The applicant's deal room shows the program owner's company logo (Tujitume's mark is only the fallback). */
class DealRoomLogoTest extends ProgramTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Programs\Program::forgetLogoCache();
    }

    private function dealRoom()
    {
        return $this->actingAs($this->applicantUser, 'sanctum')->getJson("/api/v1/programs/dealroom/applications/{$this->application->id}")->assertOk();
    }

    public function test_the_deal_room_sends_the_organization_logo_and_currency(): void
    {
        UserSetting::updateOrCreate(['user_id' => $this->orgUser->id], ['logo' => 'https://cdn.example.com/era.png']);

        $program = $this->dealRoom()->json('application.program');

        $this->assertSame('https://cdn.example.com/era.png', $program['organization_logo']);
        $this->assertArrayHasKey('currency', $program);
    }

    public function test_a_program_made_by_a_team_member_uses_the_owners_logo(): void
    {
        $teamMember = User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
        $this->program->update(['user_id' => $teamMember->id, 'organization_id' => $this->organization->id]);
        UserSetting::updateOrCreate(['user_id' => $this->orgUser->id], ['logo' => 'https://cdn.example.com/owner.png']);

        $this->assertSame('https://cdn.example.com/owner.png', $this->dealRoom()->json('application.program.organization_logo'));
    }

    public function test_no_hosted_logo_means_no_logo_so_the_page_falls_back_to_tujitume(): void
    {
        UserSetting::updateOrCreate(['user_id' => $this->orgUser->id], ['logo' => 'data:image/png;base64,AAAA']);
        \App\Models\Programs\Program::forgetLogoCache();

        $this->assertNull($this->dealRoom()->json('application.program.organization_logo'));
    }
}
