<?php

use App\Models\Business\Listing;
use App\Models\Communication\Notifications;
use App\Models\Milestones\Milestones;
use App\Service\Business\Milestone\MilestoneLifecycleService;

uses(Tests\Feature\Programs\ProgramTestCase::class);

// A business milestone past its deadline under 60% progress is flagged non-compliant, its 72-hour window opens and the
// owner is told once (the investors' side is in tests/Feature/Business/NonComplianceEscalationTest.php)
it('flags an overdue milestone non-compliant and tells the owner once, in-app and by email', function () {
    $listing = Listing::create(['user_id' => $this->applicantUser->id, 'name' => 'Maji Kiosk', 'category' => 'Water', 'stage' => 'seed',
        'location' => 'Nairobi, Kenya', 'investment_needed' => 1000, 'amount_collected' => 0, 'active' => 1, 'threshold_met' => 0]);
    $m = Milestones::create(['user_id' => $this->applicantUser->id, 'listing_id' => $listing->id, 'title' => 'Build kiosk', 'amount' => 500,
        'status' => 'to_do', 'progress_percentage' => 30, 'deadline_date' => now()->subDays(2)]);

    $service = new MilestoneLifecycleService();
    $service->evaluateListing($listing->fresh());
    $service->evaluateListing($listing->fresh()); // the next run does not tell them again

    expect($m->fresh()->status)->toBe('non_compliant');
    $notices = Notifications::where('receiver_id', $this->applicantUser->id)->where('text', 'like', '%non-compliant%')->get();
    expect($notices)->toHaveCount(1)->and($notices[0]->text)->toContain('Build kiosk')->not->toContain('extended');

    $mail = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($x) => $x->getOriginalMessage())
        ->first(fn ($e) => str_contains((string) $e->getSubject(), 'Non-Compliant'));
    expect($mail)->not->toBeNull()->and(html_entity_decode($mail->getHtmlBody()))->toContain('Maji Kiosk')->toContain('% progress')->toContain('72 hours')
        ->and(\App\Models\Milestones\NonCompliance\MilestoneNonCompliance::where('milestone_id', $m->id)->value('stage'))->toBe('response_window');
});
