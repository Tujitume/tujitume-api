<?php

namespace Tests\Feature\Capital;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalMilestone;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\CapitalProfile;
use App\Models\Capital\CapitalTermsAgreement;
use App\Models\Capital\StartupPitches;
use Tests\Feature\Programs\ProgramTestCase;

/** A capital provider (type 5) with an open offer, and the entrepreneur from ProgramTestCase */
abstract class CapitalTestCase extends ProgramTestCase
{
    public User $capitalOwner;

    public CapitalOffer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([10001 => 'super_admin', 10002 => 'editor', 10003 => 'viewer'] as $id => $name) {
            Role::where('name', $name)->where('id', '!=', $id)->delete();
            \Illuminate\Support\Facades\DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name, 'access_types' => json_encode([])]);
        }
        $this->capitalOwner = $this->makeCapitalOwner();
        $this->offer = $this->makeOffer($this->capitalOwner);
    }

    protected function makeCapitalOwner(array $attributes = []): User
    {
        $owner = User::factory()->create($attributes + ['user_type_id' => 5]);
        CapitalProfile::create(['user_id' => $owner->id, 'org_name' => 'Acacia Fund', 'org_type' => 'VC', 'active' => 1]);

        return $owner;
    }

    /** A capital team member of $owner with role super_admin | editor | viewer */
    protected function teamMember(User $owner, string $role): User
    {
        $member = User::factory()->create(['user_type_id' => 5]);
        CapitalProfile::create(['user_id' => $member->id, 'role_id' => Role::where('name', $role)->value('id'), 'capital_owner_id' => $owner->id, 'active' => 1]);

        return $member;
    }

    protected function makeOffer(User $owner, array $attributes = []): CapitalOffer
    {
        return CapitalOffer::create($attributes + [
            'user_id' => $owner->id, 'offer_title' => 'Green Fund '.uniqid(), 'total_capital_available' => 100000, 'available_amount' => 100000,
            'per_startup_allocation' => 10000, 'sectors' => ['Agriculture'], 'regions' => ['Kenya'], 'startup_stage' => ['seed'],
            'impact_objectives' => 'clean water and rural jobs', 'visible' => 1, 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addMonth()->toDateString(),
        ]);
    }

    protected function makeListing(User $owner): Listing
    {
        return Listing::create(['user_id' => $owner->id, 'name' => 'Water kiosks', 'category' => 'Water', 'location' => 'Nairobi', 'investment_needed' => 900000, 'share' => 15]);
    }

    protected function makePitch(?CapitalOffer $offer = null, int $status = StartupPitches::STATUS_PENDING, ?User $applicant = null): StartupPitches
    {
        $offer ??= $this->offer;
        $applicant ??= $this->applicantUser;

        return StartupPitches::create([
            'capital_id' => $offer->id, 'user_id' => $applicant->id, 'capital_owner_id' => $offer->user_id, 'startup_name' => 'Maji Startup',
            'contact_person_name' => 'Amina', 'contact_person_email' => 'amina@example.test', 'sector' => 'Agriculture', 'headquarters_location' => 'Kenya',
            'stage' => 'seed', 'burn_rate' => 0, 'total_amount_requested' => 5000, 'status' => $status, 'score' => 70,
        ]);
    }

    /** An accepted application with two milestones (2000 then 3000) and accepted terms */
    protected function fundablePitch(): array
    {
        $pitch = $this->makePitch(status: StartupPitches::STATUS_ACCEPTED);
        $first = CapitalMilestone::create(['app_id' => $pitch->id, 'title' => 'Pilot', 'amount' => 2000, 'description' => null, 'document' => '']);
        $second = CapitalMilestone::create(['app_id' => $pitch->id, 'title' => 'Scale', 'amount' => 3000, 'description' => null, 'document' => '']);
        CapitalTermsAgreement::create(['capital_id' => $pitch->capital_id, 'pitch_id' => $pitch->id, 'business_owner_id' => $pitch->user_id, 'capital_owner_id' => $pitch->capital_owner_id, 'status' => 'accepted', 'document' => 'files/x.pdf']);
        $pitch->update(['terms_agreed' => 1]);

        return [$pitch, $first, $second];
    }

    protected function asCapital(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->capitalOwner, 'sanctum');
    }
}
