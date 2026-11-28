<?php

namespace Tests\Feature\Audits;

use App\Models\Auth\User;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Services;
use App\Models\Programs\ProgramMilestone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Programs\ProgramTestCase;

/**
 * The project manager's Pending requests list service booking and program audits waiting for them (only business and
 * capital audits were listed, so a service audit to accept never showed there).
 */
class PmAuditPendingTasksTest extends ProgramTestCase
{
    private function pending(User $pm)
    {
        Cache::flush();

        return collect($this->actingAs($pm, 'sanctum')->getJson('/api/v1/pending-tasks?fresh=1')->assertOk()->json('data.groups'))->flatMap(fn ($g) => $g['items']);
    }

    public function test_service_and_program_audits_waiting_for_the_pm_are_listed_until_they_are_done(): void
    {
        $pm = User::factory()->create(['user_type_id' => 3]);
        $provider = User::factory()->create(['user_type_id' => 3]);
        $customer = User::factory()->create(['user_type_id' => 1]);
        $service = Services::create(['user_id' => $provider->id, 'name' => 'Website Build', 'price' => 8000, 'category' => 'IT', 'location' => 'Nairobi', 'details' => 'Site', 'active' => 1]);
        $booking = ServiceBooking::create(['service_id' => $service->id, 'booker_id' => $customer->id, 'service_owner_id' => $provider->id, 'date' => now()->addDay()->toDateString(), 'status' => 'paid', 'paid' => 1]);
        $mile = ServiceBookingMilestone::create(['service_id' => $service->id, 'booker_id' => $customer->id, 'booking_id' => $booking->id, 'title' => 'Site delivered', 'amount' => 8000, 'status' => 'Done', 'auditor_id' => $pm->id, 'audit_status' => 'pending', 'audit_requested_at' => now()]);

        // A real program milestone: CI's SQLite enforces foreign keys, and switching them off inside RefreshDatabase's transaction does nothing
        $programMilestone = ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => 1, 'amount' => 5000, 'status' => 'submitted']);
        $verification = DB::table('milestone_verifications')->insertGetId(['milestone_id' => $programMilestone->id, 'submitted_by' => $this->applicantUser->id, 'verification_type' => 'mid_milestone', 'decision' => 'audit_requested', 'auditor_id' => $pm->id, 'created_at' => now(), 'updated_at' => now()]);

        $items = $this->pending($pm);
        $service = $items->firstWhere('type', 'audit.service');
        $this->assertSame('Service audit to accept or decline', $service['title']);
        $this->assertSame('dashboard.serviceProvider.pmAudits.finalApproval?source=service', $service['link']);
        $program = $items->firstWhere('type', 'audit.program');
        $this->assertSame('dashboard.serviceProvider.pmAudits.midMilestone?source=program', $program['link']);

        // accepted and paid: still waiting for the report
        $mile->update(['audit_status' => 'accepted']);
        $this->assertSame('Service audit report to send', $this->pending($pm)->firstWhere('type', 'audit.service')['title']);

        // quoted (waiting for the customer's payment) or reported: nothing for the PM to do
        $mile->update(['audit_status' => 'reported']);
        DB::table('milestone_verifications')->where('id', $verification)->update(['decision' => 'approved']);
        $items = $this->pending($pm);
        $this->assertNull($items->firstWhere('type', 'audit.service'));
        $this->assertNull($items->firstWhere('type', 'audit.program'));

        // someone else's audit never shows
        $this->assertNull($this->pending($provider)->firstWhere('type', 'audit.service'));
    }
}
