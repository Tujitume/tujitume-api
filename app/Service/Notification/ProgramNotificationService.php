<?php
namespace App\Service\Notification;
use App\Models\Communication\Notifications;
use App\Models\ProgramEmailTemplate;
use App\Models\Programs\ProgramApplication;
use Illuminate\Support\Facades\Log;

class ProgramNotificationService
{
    protected EmailService $emailService;
    protected NotificationService $notification;
    protected string $view_base;

    /**
     * Events that reach applicants: these emails are dressed in the program owner's
     * name, logo and accent colour instead of Tujitume's (see EmailBrand).
     */
    private const BRANDED_EVENTS = [
        'application.received', 'application.accepted', 'application.rejected', 'application.awarded',
        'round.opened', 'round.closing_soon', 'round.closed', 'round.advanced', 'round.not_selected',
        'round.score_received', 'milestones.created', 'budget.completed',
        'supplier.added',
        'mprv.approved', 'mprv.rejected', 'completion.approved', 'completion.rejected',
        'milestone.unlocked', 'milestone.funds_released', 'dealroom.document_uploaded',
        'disbursement.created', 'disbursement.completed', 'disbursement.reversed',
        'funding_plan.revision_requested',
        'funding_plan.sent_to_applicant', 'funding_plan.changes_requested', 'funding_plan.activated',
        'agreement.agreed', 'agreement.message', 'agreement.answered',
        'milestone_verification.approved', 'milestone_verification.rejected',
        'milestone_verification.modification_requested',
        'applicant.checkpoint_sent',
        'applicant.site_visit_planned',
    ];

    public function __construct() {
        $this->emailService = new EmailService();
        $this->notification = new NotificationService();
        $this->view_base    = 'programs.';
    }

    /**
     * Send notification + email
     */
    public function send(string $event, array $recipients, array $data = []): void
    {
        // Who did it: the signed-in person, unless the caller already said (cron jobs have nobody signed in)
        if (empty($data['actor_name']) && auth()->check()) {
            $data['actor_name'] = trim((auth()->user()->first_name ?? '') . ' ' . (auth()->user()->last_name ?? ''));
        }

        // Money is always shown in the program's own currency (KES by default), whatever the caller passed
        $data = $this->withCurrency($data);

        $config = $this->getEventConfig($event, $data);
        // The bell shows a short headline and then the sentence, split by a line break (see normalizeNotification)
        $message = $config['title'] . "\n" . ($this->messageFor($event, $data) ?? $config['message']);
        $type = in_array($event, ['wallet.deposited']) ? 'deposit' : 'program';

        // The four emails an owner may write: their own version, else the system default, in the program's language
        $ownEmail = in_array($event, ProgramEmailTemplate::CUSTOMISABLE_EVENTS, true)
            ? $this->ownerEmail($event, $data)
            : null;
        // An award is the "Application accepted" email of the Notifications step ("sent when an application is fully
        // accepted / awarded"): the owner's own version when they wrote one, else the award email with its amount
        if ($event === 'application.awarded') {
            $ownEmail = $this->ownerEmail('application.accepted', $data, savedOnly: true);
        }
        if ($ownEmail) {
            $data['program_id'] = $ownEmail['program_id'];
        }

        // Applicant events wear the program owner's brand; everything else is left to
        // EmailService, which dresses each email in its recipient's own organisation.
        $brand = in_array($event, self::BRANDED_EVENTS) ? EmailBrand::forData($data) : null;

        foreach ($recipients as $recipient) {
            if (!$recipient) continue;
            // The system AI reviewer account never receives notifications or email
            if (strcasecmp((string) ($recipient->email ?? ''), (string) config('ai_review.system_user_email')) === 0) continue;

            // Events that reach people in different portals carry one link per user type
            $link = $this->linkFor($config, $recipient);

            // customer_id is who caused the notification (the signed-in user), else the recipient themselves.
            // The in-app notification must never stop the email (or the next recipient), so it fails on its own.
            try {
                $this->notification->create(
                    $recipient->id, $data['actor_id'] ?? auth()->id() ?? $recipient->id, $message, $link, $type,
                );
            } catch (\Throwable $e) {
                Log::error("Notification '{$event}' for user {$recipient->id} was not saved: " . $e->getMessage());
            }

            $emailData = array_merge($data, [
                'recipientName'  => $recipient->first_name ?? $recipient->name,
                'recipientEmail' => $recipient->email,
                // The email button opens exactly what the in-app notification opens
                'action_url'     => EmailLink::url($link, $recipient),
            ] + ($brand ? ['brand' => $brand] : []));

            $subject = $config['email_subject'];
            $view = $config['email_view'];
            if ($ownEmail) {
                [$subject, $view, $emailData] = $this->fillOwnerEmail($ownEmail, $emailData, $recipient);
            }

            $this->emailService->send($subject, $view, $emailData, $recipient->email);
        }
    }

    // Read a field of the event data without ever throwing. A missing field gives a plain-words fallback,
    // so one forgotten value can no longer stop a notification (and its email) from being created.
    private const FALLBACKS = [
        'program_title' => 'the program', 'round_name' => 'the round', 'business_name' => 'An applicant',
        'reviewer_name' => 'A reviewer', 'supplier_name' => 'the supplier', 'uploader_name' => 'Someone',
        'actor_name' => 'The program team', 'milestone_number' => '', 'document_type' => 'a document',
        'reason' => 'no reason given', 'order_type' => 'review', 'days_left' => 'a few',
        'verification_label' => 'verification',
        'decision_notes' => 'no additional details were provided',
    ];

    // -------------------------- H E L P E R S ---------------------------------//

    /**
     * What one of the owner-writable emails says for this program: the owner's saved subject and body, or the
     * system default for anything they left out, in the program's email language. The program is found from
     * the application when the caller only passed application_id. Null when the program is not known.
     */
    private function ownerEmail(string $event, array $data, bool $savedOnly = false): ?array
    {
        $programId = (int) ($data['program_id'] ?? 0);
        $application = null;
        if (!empty($data['application_id'])) {
            $application = ProgramApplication::query()->select(['id', 'program_id', 'awarded_amount'])->find($data['application_id']);
            $programId = $programId ?: (int) ($application?->program_id ?? 0);
        }
        if (!$programId) {
            return null;
        }

        $program = \App\Models\Programs\Program::query()->select(['id', 'email_locale', 'funding_per_business'])->find($programId);
        if (!$program) {
            return null;
        }

        $locale = ProgramEmailDefaults::locale($program->email_locale);
        $default = ProgramEmailDefaults::forEvent($event, $locale);
        $saved = ProgramEmailTemplate::resolve($programId, $event);
        if ($savedOnly && !$saved) {
            return null;
        }
        $award = $data['award_amount'] ?? $data['amount'] ?? $application?->awarded_amount ?? $program->funding_per_business;

        return [
            'program_id' => $programId,
            'locale' => $locale,
            'subject' => $saved?->subject ?: $default['subject'],
            'body_html' => $saved?->body_html ?: $default['body_html'],
            'heading' => $default['heading'],
            'button_label' => $default['button_label'],
            'award_amount' => $award !== null && $award !== '' ? $this->money(array_merge($data, ['award_amount' => $award]), 'award_amount') : '',
        ];
    }

    /** [subject, view, data] for one recipient of an owner-writable email, with every {{token}} filled in. */
    private function fillOwnerEmail(array $email, array $data, $recipient): array
    {
        $values = [
            'applicant_name' => $recipient->first_name ?? $recipient->name ?? '',
            'program_title' => $data['program_title'] ?? '',
            'round_name' => $data['round_name'] ?? '',
            'award_amount' => $email['award_amount'],
            'next_steps_url' => $data['action_url'] ?? '',
            'dashboard_url' => $data['action_url'] ?? '',
        ];

        return [
            ProgramEmailDefaults::renderSubject($email['subject'], $values, $email['locale']),
            $this->view_base . 'program_email',
            array_merge($data, [
                'email_locale' => $email['locale'],
                'email_heading' => $email['heading'],
                'email_button' => $email['button_label'],
                'email_body' => ProgramEmailDefaults::render($email['body_html'], $values, $email['locale']),
            ]),
        ];
    }

    public function v(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') return self::FALLBACKS[$key] ?? '';
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Sets `currency` (and `fee_currency`) to the program's currency, or the app currency when the program is
     * not known. A missing value or a stray "USD" is replaced; any other explicit currency is kept.
     */
    private function withCurrency(array $data): array
    {
        $fallback = null;
        $resolve = function () use (&$fallback, $data) {
            if ($fallback !== null) return $fallback;
            $programId = $data['program_id'] ?? null;
            $fromProgram = $programId ? \App\Models\Programs\Program::whereKey($programId)->value('currency') : null;
            return $fallback = strtoupper((string) ($fromProgram ?: config('app.currency', 'KES')));
        };

        foreach (['currency', 'fee_currency'] as $key) {
            $given = strtoupper((string) ($data[$key] ?? ''));
            if ($given === '' || $given === 'USD') {
                // fee_currency is only filled in when the event is about a fee
                if ($key === 'currency' || array_key_exists($key, $data)) {
                    $data[$key] = $resolve();
                }
            }
        }

        return $data;
    }

    /** "KES 5,000.00" from an amount field and the currency (the program's, else none). */
    private function money(array $data, string $key = 'amount'): string
    {
        $raw = $data[$key] ?? null;
        if ($raw === null || $raw === '') return '';
        $number = is_numeric($raw) ? number_format((float) $raw, 2) : (string) $raw;
        return trim(($data['currency'] ?? $data['fee_currency'] ?? '') . ' ' . $number);
    }

    /** "Milestone 2" when the number is known, else "the milestone". */
    private function milestoneLabel(array $data): string
    {
        $n = $this->v($data, 'milestone_number');
        return $n === '' ? 'the milestone' : "Milestone {$n}";
    }

    /**
     * The sentence shown in the bell. It says who did what, in which program, and what to do next,
     * so the person never has to open it to find out why they were notified.
     * Returns null for an event with no wording here (its configured message is used).
     */
    private function messageFor(string $event, array $d): ?string
    {
        $v = fn (string $k) => $this->v($d, $k);
        $program = $v('program_title');
        $round = $v('round_name');
        $actor = $v('actor_name');
        $ms = $this->milestoneLabel($d);

        return match ($event) {
            'application.submitted' => "{$v('business_name')} applied to {$program}. Open the application to review it.",
            'application.received' => "Thank you for applying. {$program} received your application and will tell you the result once the round is reviewed.",
            'application.accepted' => "Good news: {$program} accepted your application. Open your deal room to set up your funding plan.",
            'application.rejected' => "{$program} did not select your application this time. You can look at other programs and apply again.",
            'round.opened' => "{$round} of {$program} is now open. Apply before it closes.",
            'round.closing_soon' => "{$round} of {$program} closes in {$v('days_left')} days" . ($v('closes_at') !== '' ? " ({$v('closes_at')})" : '') . '. Submit your application before then.',
            'round.closed' => "{$round} of {$program} is closed. Your application is now being reviewed and we will tell you the result.",
            'round.advanced' => "You moved on to {$round} of {$program}. Open your application to complete this round.",
            'round.not_selected' => "Your application was not selected to continue after {$round} of {$program}. Thank you for taking part.",
            'round.scoring_assigned' => ($d['assignment'] ?? 'request') === 'total'
                ? "You now have " . ($d['max_apps'] ?? 0) . " application(s) to review in {$round} of {$program}. Open Applications to see them."
                : (($d['assignment'] ?? 'request') === 'applications'
                ? ($d['max_apps'] ?? null ? "{$d['max_apps']} application(s)" : 'Applications') . " from {$round} of {$program} were assigned to you. Open Applications to start reviewing."
                : "{$actor} asked you to review {$round} of {$program}. Open your requests to accept or decline."),
            'round.reviewer_invited_internal', 'round.reviewer_invited_external' => "You were invited to review applications for {$program}. Accept the invitation to get started.",
            'round.score_received' => "Your application to {$program} was reviewed. Open it to see the update.",
            // The amount is only known once the funding plan is set: never say "awarded you KES 0.00"
            'application.awarded' => "Congratulations! {$program} awarded you " . ((float) ($d['amount'] ?? 0) > 0 ? $this->money($d) : 'funding') . ". Open your funding plan to set up your milestones.",
            'milestones.created' => "{$v('milestone_count')} milestones were created for your funding plan in {$program}. Open your plan to review them.",
            'funding_plan.submitted' => "{$v('business_name')} submitted a funding plan for {$program}. Review the milestones and funding setup.",
            'funding_plan.revision_requested' => "{$actor} sent your funding plan for {$program} back for revision. Open your deal room to review the plan.",
            'funding_plan.sent_to_applicant' => "{$actor} sent the funding plan for {$program}. Review it in your deal room.",
            'funding_plan.changes_requested' => "{$actor} requested changes to your funding plan for {$program}: {$v('revision_notes')} ({$v('checklist_count')} checklist item(s)).",
            'funding_plan.activated' => "Your funding plan for {$program} is active. You can start working on its milestones.",
            'agreement.agreed' => "{$v('business_name')} agreed to {$v('verification_label')} for {$this->milestoneLabel($d)} with {$v('request_count')} request(s). Review the agreement.",
            'agreement.message' => "{$actor} sent a message about {$v('verification_label')} for {$this->milestoneLabel($d)}.",
            'agreement.answered' => "The program owner updated the {$v('verification_label')} instructions for {$this->milestoneLabel($d)}. Review the updated questions.",
            'milestone_verification.approved' => "{$actor} approved your {$v('verification_label')} for {$ms} in {$program}.",
            'milestone_verification.rejected' => "{$actor} rejected your {$v('verification_label')} for {$ms} in {$program}: {$v('decision_notes')}.",
            'milestone_verification.modification_requested' => "{$actor} requested changes to your {$v('verification_label')} for {$ms} in {$program}: {$v('decision_notes')}.",
            'supplier.added' => "You have been added as a supplier to {$ms} for {$program}. Open your account to view the assignment.",
            'budget.completed' => "The budget for {$ms} is complete. You can now submit your MPRV.",
            'mprv.submitted' => "{$v('business_name')} submitted the MPRV for {$ms}. Review it, then approve it or ask for changes.",
            'verification.audit_needed' => "{$v('business_name')} sent the {$v('check')} for {$ms} again after it was sent back twice. Pick a project manager to audit it before it is decided.",
            'agreement.changes_requested' => "{$actor} asked for changes to the {$v('check')} instructions for {$ms}: {$v('reason')}. Reply in the step conversation and agree again.",
            'verification.audit_needed_applicant' => "Your {$v('check')} for {$ms} was sent back twice, so this submission goes to an independent project manager. You will be told the result.",
            'application.completed_owner' => "{$v('business_name')} has completed {$program}: every milestone is approved and paid.",
            'application.completed' => "Your program is complete: every milestone of {$v('business_name')}'s funding plan for {$program} is approved and paid.",
            'mid_milestone.submitted' => "{$v('business_name')} submitted the mid-milestone check for {$ms}. Review it, then approve it or ask for changes.",
            'final_approval.submitted' => "{$v('business_name')} submitted final approval for {$ms}. Review it, then approve it or ask for changes.",
            'mprv.approved' => "{$actor} approved your MPRV for {$ms}. The funds can now be released.",
            'mprv.rejected' => "{$actor} asked for changes to your MPRV for {$ms}: {$v('reason')}. Update it and submit it again.",
            'mprv.audit_requested' => "You were asked to audit {$v('business_name')} for {$ms}. Open Project Manager Audits to accept or decline.",
            'mprv.audit_accepted' => "{$actor} accepted the audit of {$v('business_name')} for {$ms}. You will be told when the report is in.",
            'mprv.audit_declined' => "{$actor} declined the audit of {$v('business_name')} for {$ms}. You can decide it yourself or ask another project manager.",
            'mprv.audit_completed' => "{$actor} sent the audit report for {$v('business_name')}, {$ms}: recommends to {$v('recommendation')}. Open the deal room to review it and decide.",
            // DECISION-9 / DECISION-8 change: paid audits, and the owner cancelling one
            'mprv.audit_quoted' => "{$actor} accepted the audit of {$v('business_name')} for {$ms} for a fee of {$v('fee_label')}. Pay it from the organization wallet to start the audit.",
            'mprv.audit_paid' => "The audit fee of {$v('fee_label')} for {$v('business_name')}, {$ms} is held for you. Start the audit and send your report: you are paid when it is in.",
            'mprv.audit_cancelled' => "{$actor} cancelled the audit of {$v('business_name')} for {$ms}" . (filled($d['reason'] ?? null) ? ": {$v('reason')}" : '') . '. Any fee held is refunded to the payer.',
            'disbursement.created' => "A payment of " . ($this->money($d) ?: 'funds') . " to {$v('supplier_name')} for {$ms} was started. We will tell you when it completes.",
            'disbursement.supplier_processing' => "A payment of " . ($this->money($d) ?: 'funds') . " is on its way to you. We will confirm when it arrives.",
            'disbursement.completed' => "The payment of " . ($this->money($d) ?: 'funds') . " to {$v('supplier_name')} went through.",
            'disbursement.supplier_confirmed' => ($this->money($d) ?: 'A payment') . " was sent to you for {$program}. Please confirm that you received it.",
            'disbursement.failed' => "The payment to {$v('supplier_name')} failed: {$v('reason')}. Open the deal room to try again.",
            'disbursement.reversed' => "The payment of " . ($this->money($d) ?: 'funds') . " to {$v('supplier_name')} was reversed. Open the deal room for details.",
            'milestone.funds_released' => "All payments for {$ms} are done.",
            'dealroom.document_uploaded' => "{$v('uploader_name')} uploaded {$v('document_type')} for {$ms}. Open the deal room to view it.",
            'completion.submitted' => "{$v('business_name')} says {$ms} is complete. Review it under Final approval.",
            'completion.approved' => "{$actor} approved your completion of {$ms}.",
            'completion.rejected' => "{$actor} asked for changes to your completion of {$ms}: {$v('reason')}. Update it and submit again.",
            'milestone.unlocked' => "{$ms} is unlocked. You can start work on it.",
            'wallet.deposited' => ($this->money($d) ?: 'Funds') . " was added to the {$program} wallet.",
            'wallet.activated' => "The {$program} wallet is active. You can now release funds.",
            'wallet.low_balance' => "The {$program} wallet is running low (" . ($this->money($d, 'balance') ?: 'low balance') . "). Add funds so payments do not stop.",
            'program.published' => "{$program} is published.",
            'program.opened' => "{$program} is now accepting applications.",
            'program.awarded' => "All rounds of {$program} are finished and {$v('awarded_count')} awardee(s) were selected. Open the deal room to start funding setup.",
            'import.finished' => "Your import finished: {$v('created')} of {$v('total')} program(s) created in {$v('duration')}." . ((int) ($d['failed'] ?? 0) > 0 ? " {$v('failed')} need attention: open the import to see why." : ''),
            'program.closed' => "{$program} is closed to new applications.",
            'program.finalized' => "{$program} is complete: {$v('awarded_count')} business(es) funded.",
            'reviewer.scoring_complete' => "{$v('reviewer_name')} finished scoring {$round}. Check the results and finalize the round.",
            'round.all_applications_scored' => "Every application in {$round} is scored. Finalize the round to move the applicants on.",
            'reviewer.accepted' => "{$v('reviewer_name')} accepted your request to review {$round} of {$program}.",
            'reviewer.accepted_insufficient_funds' => "A reviewer accepted {$round} of {$program}, but the wallet is short by " . ($this->money($d, 'shortfall') ?: 'the review fees') . ". Deposit that amount so reviews can start.",
            'reviewer.work_delivered' => "{$v('reviewer_name')} submitted their {$v('order_type')} work for {$program}. Open Orders to review and approve it.",
            'reviewer.modification_requested' => "{$actor} asked you to change your work on {$program}. Open Orders to see what to fix.",
            'reviewer.work_approved' => "Your work on {$program} was approved.",
            'reviewer.payout_setup_needed' => "Your payment for reviewing {$program} is ready, but you have no M-Pesa (LIPR) wallet set up. Open Settings, set up payouts, and the program can pay you.",
            'reviewer.site_visit_assigned' => "You were assigned a site visit for {$program}. Accept or decline the assignment.",
            'applicant.checkpoint_sent' => "A new monitoring checkpoint, {$v('checkpoint_name')}, is ready for you on {$program}. Open it to see what to submit.",
            'organization.checkpoint_assigned' => "{$v('checkpoint_name')} for {$program} was assigned to you. Open the program monitoring page to complete it.",
            'checkpoint.submitted' => "{$actor} submitted {$v('checkpoint_name')} for {$program}. Open it to verify the work or request changes.",
            'checkpoint.verified' => "{$v('checkpoint_name')} for {$program} was verified. Nothing more is needed from you.",
            'checkpoint.changes_requested' => "Changes were requested on {$v('checkpoint_name')} for {$program}. Open it to see what to fix and submit again.",
            'reviewer.fee_proposed' => "{$v('reviewer_name')} proposed a fee of " . ($this->money($d, 'proposed_fee') ?: $v('proposed_fee')) . " for the site visit on {$program}. Open the checkpoint to accept or decline it.",
            'reviewer.fee_decided' => ($d['accepted'] ?? false) ? "The organization accepted your proposed fee for a site visit under {$program}. You can now accept the visit." : "The organization declined your proposed fee for a site visit under {$program}. You can accept the visit at this fee, or decline it.",
            'reviewer.site_visit_accepted' => "{$v('reviewer_name')} accepted the site visit assignment for {$program}.",
            'reviewer.site_visit_declined' => "{$v('reviewer_name')} declined the site visit assignment for {$program}. {$v('reason')}",
            'reviewer.work_rejected' => "Your site visit work for {$program} was rejected: {$v('reason')}.",
            'reviewer.payment_initiated' => "Payment for your work on {$round} was started.",
            'reviewer.payment_completed' => ($this->money($d) ?: 'Your payment') . " for {$program} was sent to your wallet.",
            'reviewer.payment_failed' => "Paying {$v('reviewer_name')} for {$program} failed. Open Orders to retry.",
            'reviewer.declined' => "{$v('reviewer_name')} declined your request to review {$round} of {$program}. Choose another reviewer.",
            default => null,
        };
    }

    /**
     * Build a link key the frontend notificationLinkResolver.js turns into a real path.
     *
     * Format:  routeName[::applicationId][::step][::key=value ...]
     * Examples:
     *   dashboard.programOrg.programDealroomDetail::61::mprv
     *   dashboard.programOrg.applicationsMultiRound::id=3::roundId=5
     *   dashboard.entrepreneur.programsDealroom.detail::61
     *
     * A bare id (application_id) fills the route's :id; "key=value" segments fill named
     * params (id, roundId, appId, ...). A step is only added when there is an id for it.
     *
     * $query is the page's own URL state — a tab, or the modal/drawer to open — and becomes
     * "?deposit=1", "?tab=delivered", ... A value written "@42" is an id the frontend obfuscates.
     *
     * $hash is the id of the section to scroll to and highlight ("program-wallet", "milestone-3",
     * "order-42"); pages mount <HashSectionHighlighter/> and carry matching ids.
     */
    public function link(string $routeName, array $data = [], string $step = '', array $named = [], array $query = [], string $hash = ''): string
    {
        $appId = $data['application_id'] ?? null;
        $parts = [$routeName];
        if ($appId) {
            $parts[] = (string) $appId;
            if ($step) $parts[] = $step;
        }
        foreach ($named as $key => $value) {
            if ($value !== null && $value !== '') $parts[] = $key . '=' . $value;
        }
        $link = implode('::', $parts);

        $query = array_filter($query, fn ($value) => $value !== null && $value !== '' && $value !== false);
        if ($query) $link .= '?' . http_build_query($query);
        return $hash !== '' ? $link . '#' . $hash : $link;
    }

    /**
     * The link key for one recipient. Events that reach several portals list one link per
     * user type ('links'); a recipient with no entry there must not be handed another
     * portal's page, so they get their own dashboard home instead.
     */
    /** Where a checkpoint opens for each kind of person it can reach: the applicant, or a team member of the organization */
    private function checkpointLinks(array $data): array
    {
        $application = ['application_id' => $data['app_id'] ?? null];
        return [
            1 => $this->link('dashboard.entrepreneur.grantsDealroom.monitoring', $application),
            4 => $this->link('dashboard.grantOrg.dealroom.monitoring', $application),
        ];
    }

    private function linkFor(array $config, $recipient): string
    {
        $type = (int) $recipient->user_type_id;

        if (!empty($config['links'])) {
            return $config['links'][$type] ?? $this->roleHome($type);
        }

        return $config['link'] ?? $this->roleHome($type);
    }

    private function roleHome(int $userTypeId): string
    {
        return match ($userTypeId) {
            1 => 'dashboard.entrepreneur',
            2 => 'dashboard.investor',
            3 => 'dashboard.serviceProvider',
            4 => 'dashboard.programOrg',
            5 => 'dashboard.capitalOrg',
            6, 7 => 'dashboard.reviewerGrantOrg',
            default => 'dashboard',
        };
    }

    /** The deal-room card of the milestone this event is about, when we know which one. */
    private function milestoneHash(array $data): string
    {
        return isset($data['milestone_number']) ? 'milestone-' . $data['milestone_number'] : '';
    }

    /** Program owner → one applicant's page (multi-round programs open the round view). */
    public function orgApplicantLink(array $data): string
    {
        $programId = $data['program_id'] ?? null;
        $appId     = $data['application_id'] ?? null;
        if (!$programId || !$appId) return $this->link('dashboard.programOrg.applications');

        if (!empty($data['round_id'])) {
            return $this->link('dashboard.programOrg.applicationsMultiRoundApplicant', [], '', [
                'id' => $programId, 'roundId' => $data['round_id'], 'appId' => $appId,
            ], [], 'applicant-details');
        }
        return $this->link('dashboard.programOrg.applicationsSingleApplicant', [], '', [
            'id' => $programId, 'appId' => $appId,
        ], [], 'applicant-details');
    }

    /**
     * Program owner → a round's applicants (or the program's applications when no round).
     * Optional $data['tab'] (applications | reviewers | history) and $data['finalize'] = true
     * open that tab, or the "finalize round" dialog, straight away.
     */
    public function orgRoundLink(array $data): string
    {
        $programId = $data['program_id'] ?? null;
        if (!$programId) return $this->link('dashboard.programOrg.applications');
        if (empty($data['round_id'])) {
            return $this->link('dashboard.programOrg.applicationsMulti', [], '', ['id' => $programId]);
        }
        return $this->link('dashboard.programOrg.applicationsMultiRound', [], '', [
            'id' => $programId, 'roundId' => $data['round_id'],
        ], [
            'tab'      => $data['tab'] ?? null,
            'finalize' => !empty($data['finalize']) ? 1 : null,
        ], $data['hash'] ?? '');
    }

    /** Program owner → the program page with the wallet deposit dialog already open. */
    public function orgDepositLink(array $data): string
    {
        if (empty($data['program_id'])) return $this->link('dashboard.programOrg.account');
        return $this->link('dashboard.programOrg.programsManage', [], '', ['id' => $data['program_id']], ['deposit' => 1], 'program-wallet');
    }

    /** Program owner → reviewer orders, on the tab that holds them (pending | delivered | completed). */
    public function orgOrdersLink(?string $tab = null, $orderId = null): string
    {
        return $this->link('dashboard.programOrg.orders', [], '', [], ['tab' => $tab], $orderId ? 'order-' . $orderId : '');
    }

    /** Reviewer → their orders; with an order id, the delivery dialog for that order is open. */
    /**
     * Officer → the site visit itself. Business Coaches open their site visit page; a service provider's copy of
     * the link becomes their Program Work visit report (Notifications::providerLink). Without a visit, the orders list.
     */
    private function siteVisitLink(array $data): string
    {
        if (empty($data['site_visit_id'])) return $this->link('dashboard.reviewerGrantOrg.orders');
        return $this->link('dashboard.reviewerGrantOrg.siteVisitDetail', [], '', ['id' => $data['site_visit_id']]);
    }

    public function reviewerOrdersLink(array $data = []): string
    {
        $orderId = $data['order_id'] ?? null;
        return $this->link('dashboard.reviewerGrantOrg.orders', [], '', [], [
            'deliver' => $orderId ? '@' . $orderId : null,
        ], $orderId ? 'order-' . $orderId : '');
    }

    /** Program owner → the program's own manage page. */
    public function orgProgramLink(array $data): string
    {
        if (empty($data['program_id'])) return $this->link('dashboard.programOrg.programsDiscover');
        return $this->link('dashboard.programOrg.programsManage', [], '', ['id' => $data['program_id']], [], $data['hash'] ?? 'guide-manage-rounds');
    }

    /** Reviewer → the program's applications they were assigned to review. */
    private function reviewerProgramLink(array $data): string
    {
        if (empty($data['program_id'])) return $this->link('dashboard.reviewerGrantOrg.applications');
        return $this->link('dashboard.reviewerGrantOrg.applicationsMulti', [], '', ['id' => $data['program_id']]);
    }

    /** Program owner → one application's deal room, on the given tab (funding-setup, mprv, ...). */
    public function orgDealroomLink(int|string $applicationId, string $step = 'funding-setup', string $hash = ''): string
    {
        return $this->link('dashboard.programOrg.programDealroomDetail', ['application_id' => $applicationId], $step, [], [], $hash);
    }

    private function agreementDealroomLink(array $data, bool $owner, bool $openChat = false): string
    {
        $milestoneId = $data['milestone_id'] ?? null;
        $route = $owner
            ? 'dashboard.programOrg.programDealroomDetail'
            : 'dashboard.entrepreneur.programsDealroom.detail';
        $step = $owner ? 'funding-setup' : 'plan';
        $query = [];
        if ($milestoneId) {
            $query['expandedMilestone'] = '@' . $milestoneId . ':1';
            $query['verify'] = '@' . $milestoneId . ':' . ($data['verification_type'] ?? 'mprv');
            if ($openChat) $query['chat'] = $data['chat_field_id'] ?? '1';
        }
        return $this->link($route, $data, $step, [], $query, $this->milestoneHash($data));
    }

    /**
     * Program awarded → with exactly one awardee, open that business's funding setup;
     * with several, the deal room list where the owner picks one.
     */
    public function orgAwardedLink(array $data): string
    {
        $appId = $data['application_id'] ?? null;

        if (!$appId && !empty($data['program_id'])) {
            $awarded = ProgramApplication::where('program_id', $data['program_id'])
                ->whereIn('status', ['awarded', 'in_monitoring', 'completed'])
                ->limit(2)
                ->pluck('id');
            if ($awarded->count() === 1) $appId = $awarded->first();
        }

        return $appId
            ? $this->orgDealroomLink($appId)
            : $this->link('dashboard.programOrg.dealroom');
    }

    /** Applicant → their applications list, with this application's row highlighted. */
    private function applicantApplicationLink(array $data): string
    {
        $appId = $data['application_id'] ?? null;
        return $this->link('dashboard.entrepreneur.programsApplication', [], '', [], [], $appId ? 'application-' . $appId : '');
    }

    /** Applicant → the program's own page (e.g. a round just opened). */
    private function applicantProgramLink(array $data): string
    {
        if (empty($data['program_id'])) return $this->link('dashboard.entrepreneur.programsDiscover');
        return $this->link('dashboard.entrepreneur.programsDiscover.detail', [], '', ['id' => $data['program_id']], [], 'program-details');
    }

    /**
     * Applicant → their deal room, on the given tab (plan | mprv | mid | final | monitoring)
     * and, when the event is about one milestone, with that milestone highlighted.
     */
    private function applicantDealroomLink(array $data, string $step = '', bool $milestone = false): string
    {
        // A milestone event highlights that milestone; any other tab highlights its content
        $hash = ($milestone ? $this->milestoneHash($data) : '') ?: 'dealroom-content';
        return $this->link('dashboard.entrepreneur.programsDealroom.detail', $data, $step, [], [], $hash);
    }

    /** Applicant's and program owner's view of the same deal room. */
    private function dealroomLinks(array $data, string $orgStep = 'funding-setup'): array
    {
        return [
            1 => $this->applicantDealroomLink($data, 'plan', true),
            4 => $this->link('dashboard.programOrg.programDealroomDetail', $data, $orgStep, [], [], $this->milestoneHash($data)),
        ];
    }

    /**
     * Get configuration for each event type.
     * Links use dot-notation route names (resolved by frontend notificationLinkResolver.js).
     */
    /** Project Manager Audits, on the page of the audit's stage (program audits). */
    private function pmAuditLink(array $data): string
    {
        return $this->link(match ($data['audit_stage'] ?? 'pre_release') {
            'mid_milestone' => 'dashboard.serviceProvider.pmAudits.midMilestone',
            'final_approval' => 'dashboard.serviceProvider.pmAudits.finalApproval',
            default => 'dashboard.serviceProvider.pmAudits.preRelease',
        }, [], '', [], ['source' => 'program']);
    }

    protected function getEventConfig(string $event, array $data): array
    {
        return match($event) {

            // ── APPLICATION ──────────────────────────────────────────────────
            'application.submitted' => [
                'title'         => 'New Application Received',
                'message'       => "{$this->v($data, 'business_name')} has submitted an application to {$this->v($data, 'program_title')}",
                'email_subject' => 'New Program Application Submitted',
                'email_view'    => $this->view_base . 'application_submitted',
                // Program owner → applications list
                'link'          => $this->orgApplicantLink($data),
            ],

            // Applicant: a thank-you from the organization, sent the moment they submit
            'application.received' => [
                'title'         => 'Application received',
                'message'       => "{$this->v($data, 'program_title')} received your application",
                'email_subject' => 'Thank you for applying to ' . $this->v($data, 'program_title'),
                'email_view'    => $this->view_base . 'application_received',
                'link'          => $this->applicantProgramLink($data),
            ],

            'application.accepted' => [
                'title'         => 'Application Accepted',
                'message'       => "Your application to {$this->v($data, 'program_title')} has been accepted!",
                'email_subject' => 'Congratulations! Your Application Was Accepted',
                'email_view'    => $this->view_base . 'application_accepted',
                // Entrepreneur → their programs dealroom
                'link'          => $this->applicantDealroomLink($data),
            ],

            'application.rejected' => [
                'title'         => 'Application Update',
                'message'       => "Your application to {$this->v($data, 'program_title')} was not selected",
                'email_subject' => 'Program Application Update',
                'email_view'    => $this->view_base . 'application_rejected',
                'link'          => $this->link('dashboard.entrepreneur.programsDiscover'), // rejected: point to other programs
            ],

            // ── ROUNDS ───────────────────────────────────────────────────────
            'round.opened' => [
                'title'         => 'New Round Open',
                'message'       => "{$this->v($data, 'round_name')} is now open for {$this->v($data, 'program_title')}",
                'email_subject' => 'New Application Round Open',
                'email_view'    => $this->view_base . 'round_opened',
                'link'          => $this->applicantProgramLink($data),
            ],

            'round.closing_soon' => [
                'title'         => 'Round Closing Soon',
                'message'       => "{$this->v($data, 'round_name')} closes in {$this->v($data, 'days_left')} days",
                'email_subject' => 'Application Deadline Approaching',
                'email_view'    => $this->view_base . 'round_closing_soon',
                'link'          => $this->applicantProgramLink($data),
            ],

            'round.closed' => [
                'title'         => 'Round Closed',
                'message'       => "{$this->v($data, 'round_name')} is now closed for submissions",
                'email_subject' => 'Application Round Closed',
                'email_view'    => $this->view_base . 'round_closed',
                'link'          => $this->applicantApplicationLink($data),
            ],

            'round.advanced' => [
                'title'         => 'Advanced to Next Round',
                'message'       => "Congratulations! You've advanced to {$this->v($data, 'round_name')} in {$this->v($data, 'program_title')}",
                'email_subject' => 'You Advanced to the Next Round!',
                'email_view'    => $this->view_base . 'round_advanced',
                // Entrepreneur → their application in the list, highlighted
                'link'          => $this->applicantApplicationLink($data),
            ],

            'round.not_selected' => [
                'title'         => 'Round Update',
                'message'       => "Sorry your application was rejected, thank you for your participation in {$this->v($data, 'round_name')}",
                'email_subject' => 'Program Round Update',
                'email_view'    => $this->view_base . 'round_not_selected',
                'link'          => $this->link('dashboard.entrepreneur.programsDiscover'), // rejected: point to other programs
            ],

            'round.scoring_assigned' => [
                'title'         => 'New Applications to Review',
                'message'       => "You have applications assigned to review for {$this->v($data, 'program_title')}",
                'email_subject' => 'Applications Assigned for Review',
                'email_view'    => $this->view_base . 'reviewer_assigned_email',
                // TODO: deep link to round review page when built
                'link'          => $this->reviewerProgramLink($data),
                'links'         => [
                    4 => $this->link('dashboard.programOrg.applications'),
                    // A Tujitume service provider answers the request from Assigned Programs
                    3 => $this->link('dashboard.serviceProvider.grantsDiscover'),
                ],
            ],

            'round.score_received' => [
                'title'         => 'Application Reviewed',
                'message'       => "Your application for {$this->v($data, 'program_title')} has been reviewed",
                'email_subject' => 'Application Review Update',
                'email_view'    => $this->view_base . 'score_received',
                'link'          => $this->applicantApplicationLink($data),
            ],

            'round.reviewer_invited_internal' => [
                'title'         => 'Round Review Invitation',
                'message'       => "You have been invited to review applications for {$this->v($data, 'program_title')}",
                'email_subject' => 'You\'ve Been Invited to Review Program Applications',
                'email_view'    => $this->view_base . 'reviewer_invited_internal',
                'link'          => $this->reviewerProgramLink($data),
                'links'         => [4 => $this->link('dashboard.programOrg.applications')],
            ],

            'round.reviewer_invited_external' => [
                'title'         => 'Round Review Invitation',
                'message'       => "You have been invited to review applications for {$this->v($data, 'program_title')}",
                'email_subject' => 'You\'ve Been Invited to Review Program Applications on Tujitume',
                'email_view'    => $this->view_base . 'reviewer_invited_external',
                'link'          => $this->reviewerProgramLink($data),
                'links'         => [4 => $this->link('dashboard.programOrg.applications')],
            ],

            // ── AWARD ────────────────────────────────────────────────────────
            'application.awarded' => [
                'title'         => 'Program Awarded! 🎉',
                'message'       => "Congratulations! You've been awarded {$this->v($data, 'amount')} from {$this->v($data, 'program_title')}",
                'email_subject' => 'Congratulations! Program Awarded',
                'email_view'    => $this->view_base . 'application_awarded',
                'link'          => $this->applicantDealroomLink($data, 'plan'),
            ],

            'milestones.created' => [
                'title'         => 'Milestones Created',
                'message'       => "{$this->v($data, 'milestone_count')} milestones created for {$this->v($data, 'program_title')}",
                'email_subject' => 'Your Program Milestones',
                'email_view'    => $this->view_base . 'milestones_created',
                'link'          => $this->applicantDealroomLink($data, 'plan'),
            ],

            'funding_plan.submitted' => [
                'title'         => 'Funding Plan Submitted',
                'message'       => "{$this->v($data, 'business_name')} submitted a funding plan for {$this->v($data, 'program_title')}",
                'email_subject' => 'Funding Plan Ready for Review',
                'email_view'    => $this->view_base . 'funding_plan_submitted',
                'link'          => $this->orgDealroomLink($data['application_id'] ?? ''),
            ],

            'funding_plan.revision_requested' => [
                'title'         => 'Funding Plan Returned for Revision',
                'message'       => "Your funding plan for {$this->v($data, 'program_title')} was returned for revision",
                'email_subject' => 'Please Revise Your Funding Plan',
                'email_view'    => $this->view_base . 'funding_plan_revision_requested',
                'link'          => $this->applicantDealroomLink($data, 'plan'),
            ],

            'funding_plan.sent_to_applicant' => [
                'title' => 'Funding Plan Sent for Your Review',
                'message' => "The program owner sent the {$this->v($data, 'program_title')} funding plan for your review.",
                'email_subject' => 'Funding Plan Ready for Your Review',
                'email_view' => $this->view_base . 'funding_plan_sent_to_applicant',
                'link' => $this->applicantDealroomLink($data, 'plan'),
            ],
            'funding_plan.changes_requested' => [
                'title' => 'Changes Requested on Funding Plan',
                'message' => "Changes were requested to your {$this->v($data, 'program_title')} funding plan.",
                'email_subject' => 'Changes Requested on Your Funding Plan',
                'email_view' => $this->view_base . 'funding_plan_changes_requested',
                'link' => $this->link('dashboard.entrepreneur.programsDealroom.detail', $data, 'plan', [], [], 'plan-change-requests'),
            ],
            'funding_plan.activated' => [
                'title' => 'Funding Plan Activated',
                'message' => "Your funding plan for {$this->v($data, 'program_title')} is active.",
                'email_subject' => 'Your Funding Plan Is Active',
                'email_view' => $this->view_base . 'funding_plan_activated',
                'link' => $this->applicantDealroomLink($data, 'plan'),
            ],
            'agreement.agreed' => [
                'title' => 'Applicant Agreed to Verification Requests',
                'message' => "{$this->v($data, 'business_name')} agreed to {$this->v($data, 'verification_label')} with {$this->v($data, 'request_count')} request(s).",
                'email_subject' => 'Applicant Agreed to Verification Requests',
                'email_view' => $this->view_base . 'agreement_agreed',
                'link' => $this->agreementDealroomLink($data, true, true),
            ],
            'agreement.message' => [
                'title' => 'New Verification Agreement Message',
                'message' => "{$this->v($data, 'actor_name')} sent a message about {$this->v($data, 'verification_label')}.",
                'email_subject' => 'New Verification Agreement Message',
                'email_view' => $this->view_base . 'agreement_message',
                'link' => $this->agreementDealroomLink($data, ($data['actor_side'] ?? '') === 'applicant', true),
            ],
            'agreement.answered' => [
                'title' => 'Verification Questions Updated',
                'message' => "The program owner updated {$this->v($data, 'verification_label')} for Milestone {$this->v($data, 'milestone_number')}.",
                'email_subject' => 'Updated Verification Questions',
                'email_view' => $this->view_base . 'agreement_answered',
                'link' => $this->agreementDealroomLink($data, false, true),
            ],

            'milestone_verification.approved' => [
                'title'         => 'Milestone Verification Approved',
                'message'       => "Your {$this->v($data, 'verification_label')} for Milestone {$this->v($data, 'milestone_number')} was approved.",
                'email_subject' => $this->v($data, 'verification_label') . ' Approved',
                'email_view'    => $this->view_base . 'milestone_verification_approved',
                'link'          => $this->applicantDealroomLink($data, $data['verification_tab'] ?? 'plan', true),
            ],

            'milestone_verification.rejected' => [
                'title'         => 'Milestone Verification Rejected',
                'message'       => "Your {$this->v($data, 'verification_label')} for Milestone {$this->v($data, 'milestone_number')} was rejected.",
                'email_subject' => $this->v($data, 'verification_label') . ' Rejected',
                'email_view'    => $this->view_base . 'milestone_verification_rejected',
                'link'          => $this->applicantDealroomLink($data, $data['verification_tab'] ?? 'plan', true),
            ],

            'milestone_verification.modification_requested' => [
                'title'         => 'Changes Requested on Milestone Verification',
                'message'       => "Changes were requested to your {$this->v($data, 'verification_label')} for Milestone {$this->v($data, 'milestone_number')}.",
                'email_subject' => 'Changes Requested: ' . $this->v($data, 'verification_label'),
                'email_view'    => $this->view_base . 'milestone_verification_modification_requested',
                'link'          => $this->applicantDealroomLink($data, $data['verification_tab'] ?? 'plan', true),
            ],

            // ── SUPPLIER & BUDGET ────────────────────────────────────────────
            'supplier.added' => [
                'title'         => 'Milestone Supplier Assignment',
                'message'       => "You have been added as a supplier to Milestone {$this->v($data, 'milestone_number')} for {$this->v($data, 'program_title')}.",
                'email_subject' => 'You Have Been Added to a Milestone',
                'email_view'    => $this->view_base . 'supplier_added',
                'link'          => 'overview/account',
            ],

            'budget.completed' => [
                'title'         => 'Budget Ready',
                'message'       => "Budget completed for Milestone {$this->v($data, 'milestone_number')}. Ready to submit MPRV.",
                'email_subject' => 'Ready to Submit MPRV',
                'email_view'    => $this->view_base . 'budget_completed',
                'link'          => $this->applicantDealroomLink($data, 'plan', true),
            ],

            // ── MPRV ─────────────────────────────────────────────────────────
            'application.completed' => [
                'title'         => 'Program completed',
                'message'       => "Every milestone of your funding plan for {$this->v($data, 'program_title')} is approved",
                'email_subject' => 'Your program is complete',
                'email_view'    => $this->view_base . 'notice',
                'link'          => $this->applicantDealroomLink($data),
                // The program owner opens that application's deal room
            ],

            // The program owner's copy of a completed application (ISSUE-32)
            // DECISION-8: a check sent back twice waits for a project manager audit
            'verification.audit_needed' => [
                'title'         => 'Audit needed',
                'message'       => "{$this->v($data, 'business_name')}'s {$this->v($data, 'check')} for Milestone {$this->v($data, 'milestone_number')} needs a project manager audit",
                'email_subject' => 'Pick a project manager to audit',
                'email_view'    => $this->view_base . 'notice',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, (string) ($data['step'] ?? 'mprv'), [], [], $this->milestoneHash($data)),
            ],

            // ISSUE-42: a first rejection of a step agreement (the second one settles it: verification.audit_needed_applicant)
            'agreement.changes_requested' => [
                'title'         => 'Changes asked for',
                'message'       => "Changes asked for on the {$this->v($data, 'check')} of Milestone {$this->v($data, 'milestone_number')}: {$this->v($data, 'reason')}",
                'email_subject' => "Changes asked for on the {$this->v($data, 'check')} of Milestone {$this->v($data, 'milestone_number')}",
                'email_view'    => $this->view_base . 'notice',
                'link'          => $this->applicantDealroomLink($data, (string) ($data['step'] ?? 'mprv')),
            ],

            'verification.audit_needed_applicant' => [
                'title'         => 'Independent audit',
                'message'       => "Your {$this->v($data, 'check')} for Milestone {$this->v($data, 'milestone_number')} goes to an independent audit",
                'email_subject' => 'Your check goes to an independent audit',
                'email_view'    => $this->view_base . 'notice',
                'link'          => $this->applicantDealroomLink($data, (string) ($data['step'] ?? 'mprv')),
            ],

            'application.completed_owner' => [
                'title'         => 'Program completed',
                'message'       => "{$this->v($data, 'business_name')} has completed {$this->v($data, 'program_title')}",
                'email_subject' => "{$this->v($data, 'business_name')} has completed the program",
                'email_view'    => $this->view_base . 'notice',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data),
            ],

            'mid_milestone.submitted' => [
                'title'         => 'Mid-milestone Check Submitted',
                'message'       => "{$this->v($data, 'business_name')} submitted the mid-milestone check for Milestone {$this->v($data, 'milestone_number')}",
                'email_subject' => 'New Mid-milestone Check Submitted',
                'email_view'    => $this->view_base . 'notice',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'mid-milestone', [], [], $this->milestoneHash($data)),
            ],

            'final_approval.submitted' => [
                'title'         => 'Final Approval Submitted',
                'message'       => "{$this->v($data, 'business_name')} submitted final approval for Milestone {$this->v($data, 'milestone_number')}",
                'email_subject' => 'New Final Approval Submitted',
                'email_view'    => $this->view_base . 'notice',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'final-approval', [], [], $this->milestoneHash($data)),
            ],

            'mprv.submitted' => [
                'title'         => 'MPRV Submitted for Review',
                'message'       => "{$this->v($data, 'business_name')} submitted MPRV for Milestone {$this->v($data, 'milestone_number')}",
                'email_subject' => 'New MPRV Submitted',
                'email_view'    => $this->view_base . 'mprv_submitted',
                // Program owner → funding setup of that application
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'mprv', [], [], $this->milestoneHash($data)),
            ],

            'mprv.approved' => [
                'title'         => 'MPRV Approved',
                'message'       => "Your MPRV for Milestone {$this->v($data, 'milestone_number')} has been approved",
                'email_subject' => 'MPRV Approved - Funds Ready for Disbursement',
                'email_view'    => $this->view_base . 'mprv_approved',
                // Entrepreneur → the MPRV tab of their deal room
                'link'          => $this->applicantDealroomLink($data, 'mprv'),
            ],

            'mprv.rejected' => [
                'title'         => 'MPRV Requires Changes',
                'message'       => "Your MPRV for Milestone {$this->v($data, 'milestone_number')} needs revision. Reason: {$this->v($data, 'reason')}",
                'email_subject' => 'MPRV Feedback Required',
                'email_view'    => $this->view_base . 'mprv_rejected',
                'link'          => $this->applicantDealroomLink($data, 'mprv'),
            ],

            'mprv.audit_requested' => [
                'title'         => 'Audit Requested',
                'message'       => "MPRV audit requested for {$this->v($data, 'business_name')} - Milestone {$this->v($data, 'milestone_number')}",
                'email_subject' => 'MPRV Audit Assignment',
                'email_view'    => $this->view_base . 'mprv_audit_requested',
                // The auditor answers it in Project Manager Audits, on the page of its stage
                'link'          => $this->link(match ($data['audit_stage'] ?? 'pre_release') {
                    'mid_milestone' => 'dashboard.serviceProvider.pmAudits.midMilestone',
                    'final_approval' => 'dashboard.serviceProvider.pmAudits.finalApproval',
                    default => 'dashboard.serviceProvider.pmAudits.preRelease',
                }, [], '', [], ['source' => 'program']),
            ],

            'mprv.audit_accepted' => [
                'title'         => 'Audit Accepted',
                'message'       => "The project manager accepted the audit for Milestone {$this->v($data, 'milestone_number')}",
                'email_subject' => 'Audit Accepted',
                'email_view'    => $this->view_base . 'mprv_audit_update',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'mprv', [], [], $this->milestoneHash($data)),
            ],

            'mprv.audit_declined' => [
                'title'         => 'Audit Declined',
                'message'       => "The project manager declined the audit for Milestone {$this->v($data, 'milestone_number')}",
                'email_subject' => 'Audit Declined',
                'email_view'    => $this->view_base . 'mprv_audit_update',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'mprv', [], [], $this->milestoneHash($data)),
            ],

            'mprv.audit_quoted' => [
                'title'         => 'Audit fee to pay',
                'message'       => "The project manager quoted a fee for the audit of Milestone {$this->v($data, 'milestone_number')}",
                'email_subject' => 'Pay the audit fee to start the audit',
                'email_view'    => $this->view_base . 'notice',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, (string) ($data['step'] ?? 'mprv'), [], [], $this->milestoneHash($data)),
            ],

            'mprv.audit_paid' => [
                'title'         => 'Audit fee held',
                'message'       => "The audit fee for {$this->v($data, 'business_name')} - Milestone {$this->v($data, 'milestone_number')} is held for you",
                'email_subject' => 'Audit fee paid: start the audit',
                'email_view'    => $this->view_base . 'notice',
                'link'          => $this->pmAuditLink($data),
            ],

            'mprv.audit_cancelled' => [
                'title'         => 'Audit cancelled',
                'message'       => "The audit for {$this->v($data, 'business_name')} - Milestone {$this->v($data, 'milestone_number')} was cancelled",
                'email_subject' => 'Audit cancelled',
                'email_view'    => $this->view_base . 'notice',
                'link'          => $this->pmAuditLink($data),
            ],

            'mprv.audit_completed' => [
                'title'         => 'Audit Completed',
                'message'       => "Project Manager completed audit for Milestone {$this->v($data, 'milestone_number')}",
                'email_subject' => 'MPRV Audit Complete',
                'email_view'    => $this->view_base . 'mprv_audit_completed',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'mprv', [], [], $this->milestoneHash($data)),
            ],

            // ── DISBURSEMENT ─────────────────────────────────────────────────
            'disbursement.created' => [
                'title'         => 'Payment Initiated',
                'message'       => "Program payment of {$this->v($data, 'currency')} {$this->v($data, 'amount')} initiated to {$this->v($data, 'supplier_name')}",
                'email_subject' => 'Payment Processing',
                'email_view'    => $this->view_base . 'disbursement_created',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'funding-setup', [], [], $this->milestoneHash($data)),
                'links'         => $this->dealroomLinks($data),
            ],

            // The program owner paid a funding plan into escrow (MpesaCallbackController::callbackProgramDisburseToEscrow)
            'disbursement.escrow_funded' => [
                'title'         => 'Escrow funded',
                'message'       => "{$this->v($data, 'currency')} {$this->v($data, 'amount')} for {$this->v($data, 'business_name')} in {$this->v($data, 'program_title')} is now held in escrow. Release each milestone from escrow when it is approved.",
                'email_subject' => 'Your escrow payment went through',
                'email_view'    => $this->view_base . 'notice',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'funding-setup'),
            ],

            'disbursement.supplier_processing' => [
                'title'         => 'Supplier Payment Processing',
                'message'       => "A payment of {$this->v($data, 'amount')} is being processed for {$this->v($data, 'supplier_name')}",
                'email_subject' => 'Your Payment Is on the Way',
                'email_view'    => $this->view_base . 'disbursement_supplier_processing',
                'link'          => 'overview/programs/supplier',
            ],

            'disbursement.completed' => [
                'title'         => 'Payment Completed',
                'message'       => "Payment of {$this->v($data, 'amount')} completed to {$this->v($data, 'supplier_name')}",
                'email_subject' => 'Payment Successful',
                'email_view'    => $this->view_base . 'disbursement_completed',
                'link'          => $this->applicantDealroomLink($data, 'plan', true),
                'links'         => $this->dealroomLinks($data),
            ],

            'disbursement.supplier_confirmed' => [
                'title'         => 'Funds Transferred',
                'message'       => "Payment of {$this->v($data, 'amount')} has been transferred to you for {$this->v($data, 'program_title')}",
                'email_subject' => 'Payment Transferred - Please Confirm Receipt',
                'email_view'    => $this->view_base . 'disbursement_supplier_confirmed',
                'link'          => 'overview/programs/supplier/confirm/' . ($data['disbursement_id'] ?? ''),
            ],

            'disbursement.failed' => [
                'title'         => 'Payment Failed',
                'message'       => "Payment to {$this->v($data, 'supplier_name')} failed. Reason: {$this->v($data, 'reason')}",
                'email_subject' => 'Payment Failed - Action Required',
                'email_view'    => $this->view_base . 'disbursement_failed',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'funding-setup', [], [], $this->milestoneHash($data)),
            ],

            'disbursement.reversed' => [
                'title'         => 'Payment Reversed',
                'message'       => "Payment of {$this->v($data, 'amount')} to {$this->v($data, 'supplier_name')} has been reversed",
                'email_subject' => 'Payment Reversal Notice',
                'email_view'    => $this->view_base . 'disbursement_reversed',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'funding-setup', [], [], $this->milestoneHash($data)),
                'links'         => $this->dealroomLinks($data),
            ],

            'milestone.funds_released' => [
                'title'         => 'Milestone Funds Released',
                'message'       => "All payments for Milestone {$this->v($data, 'milestone_number')} have been completed",
                'email_subject' => 'Milestone Funds Fully Disbursed',
                'email_view'    => $this->view_base . 'milestone_funds_released',
                'link'          => $this->applicantDealroomLink($data, 'plan', true),
            ],

            // ── DEAL ROOM ────────────────────────────────────────────────────
            'dealroom.document_uploaded' => [
                'title'         => 'New Document Uploaded',
                'message'       => "{$this->v($data, 'uploader_name')} uploaded {$this->v($data, 'document_type')} for Milestone {$this->v($data, 'milestone_number')}",
                'email_subject' => 'New Deal Room Document',
                'email_view'    => $this->view_base . 'dealroom_document_uploaded',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'funding-setup', [], [], $this->milestoneHash($data)),
                'links'         => $this->dealroomLinks($data),
            ],

            // ── COMPLETION ───────────────────────────────────────────────────
            'completion.submitted' => [
                'title'         => 'Completion Submitted',
                'message'       => "{$this->v($data, 'business_name')} submitted completion for Milestone {$this->v($data, 'milestone_number')}",
                'email_subject' => 'Milestone Completion Submitted',
                'email_view'    => $this->view_base . 'completion_submitted',
                // Program owner → final approval step
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'final-approval', [], [], $this->milestoneHash($data)),
            ],

            'completion.approved' => [
                'title'         => 'Milestone Complete! ✅',
                'message'       => "Your completion for Milestone {$this->v($data, 'milestone_number')} has been approved",
                'email_subject' => 'Milestone Approved',
                'email_view'    => $this->view_base . 'completion_approved',
                'link'          => $this->applicantDealroomLink($data, 'final'),
            ],

            'completion.rejected' => [
                'title'         => 'Completion Needs Revision',
                'message'       => "Your completion for Milestone {$this->v($data, 'milestone_number')} requires changes. Reason: {$this->v($data, 'reason')}",
                'email_subject' => 'Milestone Completion Feedback',
                'email_view'    => $this->view_base . 'completion_rejected',
                'link'          => $this->applicantDealroomLink($data, 'final'),
            ],

            'milestone.unlocked' => [
                'title'         => 'Next Milestone Unlocked',
                'message'       => "Milestone {$this->v($data, 'milestone_number')} is now unlocked. You can begin work!",
                'email_subject' => 'New Milestone Available',
                'email_view'    => $this->view_base . 'milestone_unlocked',
                'link'          => $this->applicantDealroomLink($data, 'plan', true),
            ],

            // ── WALLET ───────────────────────────────────────────────────────
            'wallet.deposited' => [
                'title'         => 'Funds Deposited',
                'message'       => "{$this->v($data, 'amount')} deposited to {$this->v($data, 'program_title')} wallet",
                'email_subject' => 'Program Wallet Funded',
                'email_view'    => $this->view_base . 'wallet_deposited',
                'link'          => $this->orgProgramLink($data + ['hash' => 'program-wallet']),
            ],

            'wallet.activated' => [
                'title'         => 'Wallet Activated',
                'message'       => "{$this->v($data, 'program_title')} wallet is now active and ready for disbursements",
                'email_subject' => 'Program Wallet Activated',
                'email_view'    => $this->view_base . 'wallet_activated',
                'link'          => $this->link('dashboard.programOrg.dealroom'),
            ],

            'wallet.low_balance' => [
                'title'         => 'Low Wallet Balance',
                'message'       => "{$this->v($data, 'program_title')} wallet balance is low: {$this->v($data, 'balance')}",
                'email_subject' => 'Program Wallet Low Balance Alert',
                'email_view'    => $this->view_base . 'wallet_low_balance',
                // TODO: deep link to wallet page when built
                'link'          => $this->orgDepositLink($data),
            ],

            // ── PROGRAM STATUS ─────────────────────────────────────────────────
            'program.published' => [
                'title'         => 'Program Published',
                'message'       => "{$this->v($data, 'program_title')} has been published",
                'email_subject' => 'Program Successfully Published',
                'email_view'    => $this->view_base . 'program_published',
                'link'          => $this->orgProgramLink($data),
            ],

            'program.opened' => [
                'title'         => 'Program Now Open',
                'message'       => "{$this->v($data, 'program_title')} is now accepting applications",
                'email_subject' => 'Program Applications Open',
                'email_view'    => $this->view_base . 'program_opened',
                'link'          => $this->orgProgramLink($data),
            ],

            // The person who started an import, once it is done (however many runs it took)
            'import.finished' => [
                'title'         => 'Import finished',
                'message'       => "Your import finished: {$this->v($data, 'created')} of {$this->v($data, 'total')} program(s) created in {$this->v($data, 'duration')}.",
                'email_subject' => 'Your program import is finished',
                'email_view'    => $this->view_base . 'import_finished',
                'link'          => $this->link('dashboard.grantOrg.programsImport', [], '', ['importId' => $data['import_id'] ?? null], ['step' => 'import']),
            ],

            'program.awarded' => [
                'title'         => 'Awardees Selected',
                'message'       => "All rounds completed for {$this->v($data, 'program_title')}. {$this->v($data, 'awarded_count')} awardees selected. Funding setup is ready to begin.",
                'email_subject' => 'Program Awardees Selected - Funding Setup Ready',
                'email_view'    => $this->view_base . 'program_awarded',
                // Program owner → deal room list
                'link'          => $this->orgAwardedLink($data),
            ],

            'program.closed' => [
                'title'         => 'Program Closed',
                'message'       => "{$this->v($data, 'program_title')} is no longer accepting applications",
                'email_subject' => 'Program Application Period Closed',
                'email_view'    => $this->view_base . 'program_closed',
                'link'          => $this->orgProgramLink($data),
            ],

            'program.finalized' => [
                'title'         => 'Program Complete',
                'message'       => "{$this->v($data, 'program_title')} has been finalized. {$this->v($data, 'awarded_count')} businesses funded.",
                'email_subject' => 'Program Program Complete',
                'email_view'    => $this->view_base . 'program_finalized',
                'link'          => $this->orgProgramLink($data),
            ],

            // ── REVIEWER EVENTS ──────────────────────────────────────────────────
            'reviewer.scoring_complete' => [
                'title'         => 'Round Scoring Complete',
                'message'       => "{$this->v($data, 'reviewer_name')} has completed scoring for {$this->v($data, 'round_name')}. Please review and finalize.",
                'email_subject' => 'Round Scoring Complete — Ready to Finalize',
                'email_view'    => $this->view_base . 'reviewer_scoring_complete',
                'link'          => $this->orgRoundLink($data + ['tab' => 'applications', 'hash' => 'round-applications']),
            ],

            'round.all_applications_scored' => [
                'title'         => 'Round Scoring Complete',
                'message'       => "All applications for {$this->v($data, 'round_name')} have been scored. Please finalize the round and start the next round.",
                'email_subject' => 'All Applications Scored — Finalize the Round',
                'email_view'    => $this->view_base . 'round_all_applications_scored',
                'link'          => $this->orgRoundLink($data + ['finalize' => true]),
            ],

            // ── AI-ASSISTED / AI-ONLY REVIEW ─────────────────────────────────
            'ai_review.reports_ready' => [
                'title'         => 'AI Reports Ready',
                'message'       => "{$this->v($data, 'count')} AI report(s) are ready for {$this->v($data, 'round_name')} in {$this->v($data, 'program_title')}. Open an application to see the AI's suggested scores, change anything you disagree with, then submit.",
                'email_subject' => 'AI Review Reports Ready',
                'email_view'    => $this->view_base . 'ai_review_reports_ready',
                'link'          => $this->reviewerProgramLink($data),
                'links'         => [4 => $this->orgRoundLink($data + ['tab' => 'ai-review'])],
            ],

            'ai_review.round_scored' => [
                'title'         => 'Round Scored by AI',
                'message'       => "{$this->v($data, 'round_name')} has been scored by AI. Please review and finalize.",
                'email_subject' => 'Round Scored by AI — Review and Finalize',
                'email_view'    => $this->view_base . 'ai_review_round_scored',
                'link'          => $this->orgRoundLink($data + ['tab' => 'ai-review']),
            ],

            'ai_review.round_attention' => [
                'title'         => 'AI Review Needs Your Attention',
                'message'       => "AI review for {$this->v($data, 'round_name')} needs attention: {$this->v($data, 'issue')}",
                'email_subject' => 'AI Review Needs Your Attention',
                'email_view'    => $this->view_base . 'ai_review_round_attention',
                'link'          => $this->orgRoundLink($data + ['tab' => 'ai-review']),
            ],

            'reviewer.accepted' => [
                'title'         => 'Business Coach Accepted Assignment',
                'message'       => "{$this->v($data, 'reviewer_name')} accepted the review assignment for {$this->v($data, 'round_name')} in {$this->v($data, 'program_title')}.",
                'email_subject' => 'Business Coach Accepted Assignment',
                'email_view'    => $this->view_base . 'reviewer_accepted',
                'link'          => $this->orgRoundLink($data + ['tab' => 'reviewers', 'hash' => 'round-reviewers']),
            ],

            'reviewer.accepted_insufficient_funds' => [
                'title'         => 'Funds Required to Start Reviews',
                'message'       => "A Business Coach accepted the assignment for {$this->v($data, 'round_name')} in {$this->v($data, 'program_title')}. Deposit {$this->v($data, 'shortfall')} to the program wallet to enable reviews.",
                'email_subject' => 'Deposit Funds to Start Program Reviews',
                'email_view'    => $this->view_base . 'reviewer_accepted_insufficient_funds',
                'link'          => $this->orgDepositLink($data),
            ],

            'reviewer.work_delivered' => [
                'title'         => 'Business Coach Work Submitted',
                'message'       => "{$this->v($data, 'reviewer_name')} has submitted their {$this->v($data, 'order_type')} work for {$this->v($data, 'program_title')}.",
                'email_subject' => 'Business Coach Work Submitted for Review',
                'email_view'    => $this->view_base . 'reviewer_work_delivered',
                'link'          => $this->orgOrdersLink('delivered', $data['order_id'] ?? null),
            ],

            'reviewer.modification_requested' => [
                'title'         => 'Modification Requested',
                'message'       => "A modification has been requested for your work on {$this->v($data, 'program_title')}.",
                'email_subject' => 'Modification Requested — Action Required',
                'email_view'    => $this->view_base . 'reviewer_modification_requested',
                'link'          => $this->reviewerOrdersLink($data),
            ],

            'reviewer.work_approved' => [
                'title'         => 'Work Approved 🎉',
                'message'       => "Your work on {$this->v($data, 'program_title')} has been approved.",
                'email_subject' => 'Business Coach Work Approved',
                'email_view'    => $this->view_base . 'reviewer_work_approved',
                'link'          => $this->link('dashboard.reviewerGrantOrg.orders'),
            ],

            'reviewer.site_visit_assigned' => [
                'title'         => 'Site Visit Assignment',
                'message'       => "You have been assigned a site visit for {$this->v($data, 'program_title')}.",
                'email_subject' => 'New Site Visit Assignment',
                'email_view'    => $this->view_base . 'reviewer_site_visit_assigned',
                'link'          => $this->siteVisitLink($data),
            ],

            'applicant.checkpoint_sent' => [
                'title' => 'New Monitoring Checkpoint',
                'message' => "A new monitoring checkpoint, {$this->v($data, 'checkpoint_name')}, is ready for you under {$this->v($data, 'program_title')}.",
                'email_subject' => 'A Monitoring Checkpoint Is Ready for You',
                'email_view' => $this->view_base . 'applicant_checkpoint_sent',
                'link' => $this->link('dashboard.entrepreneur.grantsDealroom.monitoring', ['application_id' => $data['app_id'] ?? null]),
            ],
            'organization.checkpoint_assigned' => [
                'title' => 'Monitoring Checkpoint Assigned',
                'message' => "{$this->v($data, 'checkpoint_name')} for {$this->v($data, 'program_title')} was assigned to you.",
                'email_subject' => 'A Program Monitoring Checkpoint Was Assigned to You',
                'email_view' => $this->view_base . 'organization_checkpoint_assigned',
                'link' => $this->link('dashboard.grantOrg.dealroom.monitoring', ['application_id' => $data['app_id'] ?? null]),
            ],
            'checkpoint.submitted' => [
                'title' => 'Checkpoint Submitted',
                'message' => "{$this->v($data, 'checkpoint_name')} for {$this->v($data, 'program_title')} was submitted for your review.",
                'email_subject' => 'A Monitoring Checkpoint Was Submitted',
                'email_view' => $this->view_base . 'checkpoint_submitted',
                'link' => $this->link('dashboard.grantOrg.dealroom.monitoring', ['application_id' => $data['app_id'] ?? null]),
            ],
            'checkpoint.verified' => [
                'title' => 'Checkpoint Verified',
                'message' => "{$this->v($data, 'checkpoint_name')} for {$this->v($data, 'program_title')} was verified.",
                'email_subject' => 'Your Monitoring Checkpoint Was Verified',
                'email_view' => $this->view_base . 'checkpoint_verified',
                'links' => $this->checkpointLinks($data),
            ],
            'checkpoint.changes_requested' => [
                'title' => 'Checkpoint Changes Requested',
                'message' => "Changes were requested on {$this->v($data, 'checkpoint_name')} for {$this->v($data, 'program_title')}.",
                'email_subject' => 'Changes Were Requested on Your Monitoring Checkpoint',
                'email_view' => $this->view_base . 'checkpoint_changes_requested',
                'links' => $this->checkpointLinks($data),
            ],
            'reviewer.fee_proposed' => [
                'title' => 'Site Visit Fee Proposed',
                'message' => $this->v($data, 'reviewer_name') . ' proposed a different fee for a site visit under ' . $this->v($data, 'program_title') . '.',
                'email_subject' => 'A Site Visit Fee Was Proposed',
                'email_view' => $this->view_base . 'reviewer_fee_proposed',
                'link' => $this->link('dashboard.grantOrg.dealroom.monitoring', ['application_id' => $data['app_id'] ?? null]),
            ],
            'reviewer.fee_decided' => [
                'title' => 'Site Visit Fee Answered',
                'message' => 'The organization ' . (($data['accepted'] ?? false) ? 'accepted' : 'declined') . ' your proposed fee for a site visit under ' . $this->v($data, 'program_title') . '.',
                'email_subject' => 'Your Proposed Site Visit Fee Was Answered',
                'email_view' => $this->view_base . 'reviewer_fee_decided',
                'link' => $this->siteVisitLink($data),
            ],
            'applicant.site_visit_planned' => [
                'title' => 'Site Visit Planned',
                'message' => 'A site visit has been planned for ' . $this->v($data, 'checkpoint_name') . ' under ' . $this->v($data, 'program_title') . '.',
                'email_subject' => 'A Site Visit Is Planned',
                'email_view' => $this->view_base . 'applicant_site_visit_planned',
                'link' => $this->link('dashboard.entrepreneur.grantsDealroom.monitoring', ['application_id' => $data['app_id'] ?? null]),
            ],

            'reviewer.site_visit_accepted' => [
                'title'         => 'Site Visit Accepted',
                'message'       => "{$this->v($data, 'reviewer_name')} accepted a site visit assignment for {$this->v($data, 'program_title')}.",
                'email_subject' => 'Site Visit Assignment Accepted',
                'email_view'    => $this->view_base . 'reviewer_site_visit_accepted',
                'link'          => $this->orgOrdersLink('all', $data['order_id'] ?? null),
            ],

            'reviewer.site_visit_declined' => [
                'title'         => 'Site Visit Declined',
                'message'       => "{$this->v($data, 'reviewer_name')} declined a site visit assignment for {$this->v($data, 'program_title')}.",
                'email_subject' => 'Site Visit Assignment Declined',
                'email_view'    => $this->view_base . 'reviewer_site_visit_declined',
                'link'          => $this->orgOrdersLink('all', $data['order_id'] ?? null),
            ],

            'reviewer.work_rejected' => [
                'title'         => 'Site Visit Work Rejected',
                'message'       => "Your site visit work for {$this->v($data, 'program_title')} was rejected.",
                'email_subject' => 'Site Visit Work Rejected',
                'email_view'    => $this->view_base . 'reviewer_site_visit_rejected',
                'link'          => $this->link('dashboard.reviewerGrantOrg.orders'),
            ],

            'reviewer.payment_initiated' => [
                'title'         => 'Payment Initiated',
                'message'       => "Payment for your work on {$this->v($data, 'round_name')} has been initiated.",
                'email_subject' => 'Payment Initiated',
                'email_view'    => $this->view_base . 'reviewer_payment_initiated',
                'link'          => $this->link('dashboard.reviewerGrantOrg.orders'),
            ],

            'reviewer.payment_completed' => [
                'title'         => 'Payment Received 💰',
                'message'       => "Payment of {$this->v($data, 'amount')} {$this->v($data, 'currency')} for {$this->v($data, 'program_title')} has been transferred to your wallet.",
                'email_subject' => 'Payment Received Successfully',
                'email_view'    => $this->view_base . 'reviewer_payment_completed',
                'link'          => $this->link('dashboard.reviewerGrantOrg.orders'),
            ],

            // DECISION-2: coaches review before payouts are set up; when a payment is due and they have none, they are told
            'reviewer.payout_setup_needed' => [
                'title'         => 'Set Up Payouts To Be Paid',
                'message'       => "Your payment for {$this->v($data, 'program_title')} is ready, but you have no M-Pesa (LIPR) wallet set up",
                'email_subject' => 'Set up payouts to receive your payment',
                'email_view'    => $this->view_base . 'notice',
                'links'         => [
                    3 => $this->link('dashboard.serviceProvider.settings'),
                    6 => $this->link('dashboard.reviewerGrantOrg.settings'),
                    7 => $this->link('dashboard.reviewerGrantOrg.settings'),
                ],
            ],

            'reviewer.payment_failed' => [
                'title'         => 'Payment Failed',
                'message'       => "Payment to Business Coach {$this->v($data, 'reviewer_name')} failed for {$this->v($data, 'program_title')}. Please retry.",
                'email_subject' => 'Business Coach Payment Failed — Action Required',
                'email_view'    => $this->view_base . 'reviewer_payment_failed',
                'link'          => $this->orgOrdersLink('completed', $data['order_id'] ?? null),
            ],

            'reviewer.declined' => [
                'title'         => 'Business Coach Declined Assignment',
                'message'       => "{$this->v($data, 'reviewer_name')} declined the review assignment for {$this->v($data, 'round_name')} in {$this->v($data, 'program_title')}.",
                'email_subject' => 'Business Coach Declined Assignment — Action Required',
                'email_view'    => $this->view_base . 'reviewer_declined',
                'link'          => $this->orgRoundLink($data + ['tab' => 'reviewers', 'hash' => 'round-reviewers']),
            ],

            default => throw new \InvalidArgumentException("Unknown event type: {$event}"),
        };
    }
}
