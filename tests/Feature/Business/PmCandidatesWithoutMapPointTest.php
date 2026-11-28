<?php

namespace Tests\Feature\Business;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Milestones\Milestones;
use App\Service\Business\Milestone\MilestonePMCandidates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The project managers the investors vote from for a business audit: nearest first, but a business with no map point
 * (lat/lng empty, as when no place suggestion was picked) still gets project managers. It used to get none, so the
 * audit vote had no candidates.
 */
class PmCandidatesWithoutMapPointTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_business_without_coordinates_still_gets_project_managers(): void
    {
        $owner = User::factory()->create(['user_type_id' => 1]);
        $pm = User::factory()->create(['user_type_id' => 3]);
        $other = User::factory()->create(['user_type_id' => 3]);
        $listing = Listing::create(['user_id' => $owner->id, 'name' => 'Solar lanterns', 'category' => 'Energy', 'location' => 'Nairobi', 'investment_needed' => 1000, 'share' => 10]);
        $msId = DB::table('milestones')->insertGetId(['user_id' => $owner->id, 'listing_id' => $listing->id, 'title' => 'M1', 'amount' => 1000, 'n_o_days' => 7, 'funding_duration' => 30,
            'status' => 'to_do', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$pm->id, 'project_management'], [$other->id, 'design']] as [$uid, $category]) {
            DB::table('services')->insert(['user_id' => $uid, 'name' => 'Service '.$uid, 'category' => $category, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        $ids = (new MilestonePMCandidates())->get(Milestones::find($msId));

        $this->assertSame([$pm->id], array_map('intval', $ids));
    }
}
