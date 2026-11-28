<?php

// Fixes found by the Business Coach journey browser run (28 Nov, coach/ e2e)

use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Storage;

uses(Tests\Feature\Programs\ProgramTestCase::class);

describe('Milestones without a final approval step', function () {
    it('opens the next milestone when the payout of one without final approval lands, and not when it has one', function () {
        $make = fn (int $seq, array $extra = []) => \App\Models\Programs\ProgramMilestone::factory()->create($extra + ['app_id' => $this->application->id, 'sequence_order' => $seq,
            'is_template' => false, 'amount' => 5000, 'mprv_required' => false, 'final_approval_required' => false]);
        $first = $make(1, ['status' => 'disbursing', 'fund_release_status' => 'processing', 'fund_released' => false]);
        $second = $make(2, ['status' => 'pending', 'fund_release_status' => 'locked', 'final_approval_required' => true]);
        $third = $make(3, ['status' => 'pending', 'fund_release_status' => 'locked']);
        \App\Models\Programs\Disbursement::create(['milestone_id' => $first->id, 'recipient_type' => 'business_owner', 'amount' => 5000,
            'currency' => 'KES', 'payment_method' => 'mpesa_mobile', 'status' => 'processing', 'payment_reference' => 'TR-C1']);

        $this->postJson('/api/lipr-callback/transfer-to-supplier?sig=' . \App\Service\LiprMpesa\LiprCallback::signature('/lipr-callback/transfer-to-supplier'),
            ['transaction' => ['reference' => 'TR-C1', 'id' => 'C1', 'transactionStatus' => 'Successful', 'amount' => 5000], 'metadata' => ['listingId' => $first->id]])->assertOk();

        expect($second->fresh()->fund_release_status)->toBe('approved')->and($second->fresh()->status)->toBe('approved');

        // A plan paid before this fix (next still locked) is opened by a repeated callback
        $second->update(['fund_release_status' => 'locked', 'status' => 'pending']);
        $this->postJson('/api/lipr-callback/transfer-to-supplier?sig=' . \App\Service\LiprMpesa\LiprCallback::signature('/lipr-callback/transfer-to-supplier'),
            ['transaction' => ['reference' => 'TR-C1', 'id' => 'C1', 'transactionStatus' => 'Successful', 'amount' => 5000], 'metadata' => ['listingId' => $first->id]])->assertOk();
        expect($second->fresh()->fund_release_status)->toBe('approved');

        // The second one has a final approval step: paying it does not open the third (its approval does)
        $second->update(['fund_release_status' => 'released', 'fund_released' => true, 'status' => 'completed']);
        expect($second->fresh()->unlockNextWhenFinished())->toBeNull()
            ->and($third->fresh()->fund_release_status)->toBe('locked');
    });
});

describe('Business Coach wording in owner notices', function () {
    it('says Business Coach, never "Reviewer", when a coach delivers, accepts or declines', function () {
        config(['mail.default' => 'array']);
        $service = new \App\Service\Notification\ProgramNotificationService();
        $data = ['program_title' => 'Green Fund', 'round_name' => 'Round 1', 'reviewer_name' => 'Cora', 'order_type' => 'site_visit', 'order_id' => 1];
        foreach (['reviewer.work_delivered', 'reviewer.accepted', 'reviewer.declined', 'reviewer.payment_failed'] as $event) {
            $service->send($event, [$this->orgUser], $data);
        }

        $titles = \Illuminate\Support\Facades\DB::table('notifications')->where('receiver_id', $this->orgUser->id)->get()
            ->map(fn ($n) => $n->text)->implode("\n");
        expect($titles)->toContain('Business Coach')->not->toMatch('/\bReviewer\b/')->not->toMatch('/to reviewer /');
    });
});

describe('Owner pending: proof of completion', function () {
    it('lists a completed milestone only while its completion report waits for the owner', function () {
        $paid = \App\Models\Programs\ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => 1, 'is_template' => false,
            'status' => 'completed', 'fund_release_status' => 'released', 'fund_released' => true, 'final_approval_required' => false]);
        $reported = \App\Models\Programs\ProgramMilestone::factory()->create(['app_id' => $this->application->id, 'sequence_order' => 2, 'is_template' => false,
            'status' => 'completed', 'fund_release_status' => 'released', 'fund_released' => true, 'final_approval_required' => true]);
        \Illuminate\Support\Facades\DB::table('milestone_completion_submissions')->insert(['milestone_id' => $reported->id, 'submitted_by' => $this->applicantUser->id,
            'completion_report' => 'Done', 'decision' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        \Illuminate\Support\Facades\Cache::flush();
        $items = collect($this->actingAs($this->orgUser, 'sanctum')->getJson('/api/v1/pending-tasks?fresh=1')->assertOk()->json('data.groups'))
            ->flatMap(fn ($g) => $g['items'])->where('type', 'owner.completion');

        expect($items->pluck('id')->all())->toContain('owner.completion:' . $reported->id)->not->toContain('owner.completion:' . $paid->id);
    });
});

describe('Award notice', function () {
    it('says "awarded you funding", not KES 0.00, before the funding plan sets the amount', function () {
        config(['mail.default' => 'array']);
        $service = new \App\Service\Notification\ProgramNotificationService();
        $service->send('application.awarded', [$this->applicantUser], ['program_title' => 'Green Fund', 'amount' => '0.00', 'application_id' => $this->application->id]);
        $service->send('application.awarded', [$this->applicantUser], ['program_title' => 'Green Fund', 'amount' => 50000, 'currency' => 'KES', 'application_id' => $this->application->id]);

        $texts = \Illuminate\Support\Facades\DB::table('notifications')->where('receiver_id', $this->applicantUser->id)->orderBy('id')->pluck('text')->implode("\n");
        expect($texts)->toContain('awarded you funding')->toContain('awarded you KES 50,000.00')->not->toContain('you KES 0.00')->not->toContain('you  0.00');
    });
});

describe('Local "s3" disk', function () {
    it('gives an answer with a file a link instead of failing, when "s3" is the local disk', function () {
        config(['filesystems.disks.s3' => ['driver' => 'local', 'root' => storage_path('framework/testing/local-s3'), 'url' => 'http://127.0.0.1:8000/local-s3']]);
        Storage::forgetDisk('s3');
        AppServiceProvider::localTemporaryUrls();

        $answer = new ApplicationRoundResponse(['file_paths' => ['program-applications/plan.pdf']]);

        expect($answer->toArray()['file_paths_url'])->toBe(['http://127.0.0.1:8000/local-s3/program-applications/plan.pdf']);
    });
});
