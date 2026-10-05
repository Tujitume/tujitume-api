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
        'application.accepted', 'application.rejected', 'application.awarded',
        'round.opened', 'round.closing_soon', 'round.closed', 'round.advanced', 'round.not_selected',
        'round.score_received', 'milestones.created', 'budget.completed',
        'mprv.approved', 'mprv.rejected', 'completion.approved', 'completion.rejected',
        'milestone.unlocked', 'milestone.funds_released',
        'disbursement.created', 'disbursement.completed', 'disbursement.reversed',
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
        $config = $this->getEventConfig($event, $data);
        $type = in_array($event, ['wallet.deposited']) ? 'deposit' : 'program';

        // resolve custom email body
        $customBody = null;
        if (!empty($data['program_id']) && in_array($event, ProgramEmailTemplate::CUSTOMISABLE_EVENTS)) {
            $customBody = ProgramEmailTemplate::resolve((int) $data['program_id'], $event);

            if ($customBody) {
                $variables = [
                    'applicant_name' => $data['recipientName'] ?? 'Program Applicant',
                    'program_title' => $data['program_title'] ?? '',
                    'round_name' => $data['round_name'] ?? '',
                ];

                foreach ($variables as $key => $value) {
                    $customBody = str_replace('{{' . $key . '}}', $value, $customBody);
                }
            }
        }

        // Applicant events wear the program owner's brand; everything else is left to
        // EmailService, which dresses each email in its recipient's own organisation.
        $brand = in_array($event, self::BRANDED_EVENTS) ? EmailBrand::forData($data) : null;

        foreach ($recipients as $recipient) {
            if (!$recipient) continue;

            // Events that reach people in different portals carry one link per user type
            $link = $config['links'][$recipient->user_type_id] ?? $config['link'];

            $this->notification->create(
                $recipient->id, $recipient->id, $config['message'], $link, $type,
            );

            $this->emailService->send(
                $config['email_subject'],
                $config['email_view'],
                array_merge($data, [
                    'recipientName'  => $recipient->first_name ?? $recipient->name,
                    'recipientEmail' => $recipient->email,
                    'custom_body'    => $customBody,
                    // The email button opens exactly what the in-app notification opens
                    'action_url'     => EmailLink::url($link),
                ] + ($brand ? ['brand' => $brand] : [])),
                $recipient->email
            );
        }
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

    /**
     * Program awarded → with exactly one awardee, open that business's funding setup;
     * with several, the deal room list where the owner picks one.
     */
    public function orgAwardedLink(array $data): string
    {
        $appId = $data['application_id'] ?? null;

        if (!$appId && !empty($data['program_id'])) {
            $awarded = ProgramApplication::where('program_id', $data['program_id'])
                ->where('status', 'awarded')
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
    protected function getEventConfig(string $event, array $data): array
    {
        return match($event) {

            // ── APPLICATION ──────────────────────────────────────────────────
            'application.submitted' => [
                'title'         => 'New Application Received',
                'message'       => "{$data['business_name']} has submitted an application to {$data['program_title']}",
                'email_subject' => 'New Program Application Submitted',
                'email_view'    => $this->view_base . 'application_submitted',
                // Program owner → applications list
                'link'          => $this->orgApplicantLink($data),
            ],

            'application.accepted' => [
                'title'         => 'Application Accepted',
                'message'       => "Your application to {$data['program_title']} has been accepted!",
                'email_subject' => 'Congratulations! Your Application Was Accepted',
                'email_view'    => $this->view_base . 'application_accepted',
                // Entrepreneur → their programs dealroom
                'link'          => $this->applicantDealroomLink($data),
            ],

            'application.rejected' => [
                'title'         => 'Application Update',
                'message'       => "Your application to {$data['program_title']} was not selected",
                'email_subject' => 'Program Application Update',
                'email_view'    => $this->view_base . 'application_rejected',
                'link'          => $this->applicantApplicationLink($data),
            ],

            // ── ROUNDS ───────────────────────────────────────────────────────
            'round.opened' => [
                'title'         => 'New Round Open',
                'message'       => "{$data['round_name']} is now open for {$data['program_title']}",
                'email_subject' => 'New Application Round Open',
                'email_view'    => $this->view_base . 'round_opened',
                'link'          => $this->applicantProgramLink($data),
            ],

            'round.closing_soon' => [
                'title'         => 'Round Closing Soon',
                'message'       => "{$data['round_name']} closes in {$data['days_left']} days",
                'email_subject' => 'Application Deadline Approaching',
                'email_view'    => $this->view_base . 'round_closing_soon',
                'link'          => $this->applicantProgramLink($data),
            ],

            'round.closed' => [
                'title'         => 'Round Closed',
                'message'       => "{$data['round_name']} is now closed for submissions",
                'email_subject' => 'Application Round Closed',
                'email_view'    => $this->view_base . 'round_closed',
                'link'          => $this->applicantApplicationLink($data),
            ],

            'round.advanced' => [
                'title'         => 'Advanced to Next Round',
                'message'       => "Congratulations! You've advanced to {$data['round_name']} in {$data['program_title']}",
                'email_subject' => 'You Advanced to the Next Round!',
                'email_view'    => $this->view_base . 'round_advanced',
                // Entrepreneur → their application in the list, highlighted
                'link'          => $this->applicantApplicationLink($data),
            ],

            'round.not_selected' => [
                'title'         => 'Round Update',
                'message'       => "Sorry your application was rejected, thank you for your participation in {$data['round_name']}",
                'email_subject' => 'Program Round Update',
                'email_view'    => $this->view_base . 'round_not_selected',
                'link'          => $this->applicantApplicationLink($data),
            ],

            'round.scoring_assigned' => [
                'title'         => 'New Applications to Review',
                'message'       => "You have applications assigned to review for {$data['program_title']}",
                'email_subject' => 'Applications Assigned for Review',
                'email_view'    => $this->view_base . 'reviewer_assigned_email',
                // TODO: deep link to round review page when built
                'link'          => $this->reviewerProgramLink($data),
                'links'         => [4 => $this->link('dashboard.programOrg.applications')],
            ],

            'round.score_received' => [
                'title'         => 'Application Reviewed',
                'message'       => "Your application for {$data['program_title']} has been reviewed",
                'email_subject' => 'Application Review Update',
                'email_view'    => $this->view_base . 'score_received',
                'link'          => $this->applicantApplicationLink($data),
            ],

            'round.reviewer_invited_internal' => [
                'title'         => 'Round Review Invitation',
                'message'       => "You have been invited to review applications for {$data['program_title']}",
                'email_subject' => 'You\'ve Been Invited to Review Program Applications',
                'email_view'    => $this->view_base . 'reviewer_invited_internal',
                'link'          => $this->reviewerProgramLink($data),
                'links'         => [4 => $this->link('dashboard.programOrg.applications')],
            ],

            'round.reviewer_invited_external' => [
                'title'         => 'Round Review Invitation',
                'message'       => "You have been invited to review applications for {$data['program_title']}",
                'email_subject' => 'You\'ve Been Invited to Review Program Applications on Tujitume',
                'email_view'    => $this->view_base . 'reviewer_invited_external',
                'link'          => $this->reviewerProgramLink($data),
                'links'         => [4 => $this->link('dashboard.programOrg.applications')],
            ],

            // ── AWARD ────────────────────────────────────────────────────────
            'application.awarded' => [
                'title'         => 'Program Awarded! 🎉',
                'message'       => "Congratulations! You've been awarded {$data['amount']} from {$data['program_title']}",
                'email_subject' => 'Congratulations! Program Awarded',
                'email_view'    => $this->view_base . 'application_awarded',
                'link'          => $this->applicantDealroomLink($data, 'plan'),
            ],

            'milestones.created' => [
                'title'         => 'Milestones Created',
                'message'       => "{$data['milestone_count']} milestones created for {$data['program_title']}",
                'email_subject' => 'Your Program Milestones',
                'email_view'    => $this->view_base . 'milestones_created',
                'link'          => $this->applicantDealroomLink($data, 'plan'),
            ],

            // ── SUPPLIER & BUDGET ────────────────────────────────────────────
            'supplier.added' => [
                'title'         => 'Supplier Added',
                'message'       => "Supplier {$data['supplier_name']} added to Milestone {$data['milestone_number']}",
                'email_subject' => 'Supplier Information Added',
                'email_view'    => $this->view_base . 'supplier_added',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'funding-setup', [], [], $this->milestoneHash($data)),
            ],

            'budget.completed' => [
                'title'         => 'Budget Ready',
                'message'       => "Budget completed for Milestone {$data['milestone_number']}. Ready to submit MPRV.",
                'email_subject' => 'Ready to Submit MPRV',
                'email_view'    => $this->view_base . 'budget_completed',
                'link'          => $this->applicantDealroomLink($data, 'plan', true),
            ],

            // ── MPRV ─────────────────────────────────────────────────────────
            'mprv.submitted' => [
                'title'         => 'MPRV Submitted for Review',
                'message'       => "{$data['business_name']} submitted MPRV for Milestone {$data['milestone_number']}",
                'email_subject' => 'New MPRV Submitted',
                'email_view'    => $this->view_base . 'mprv_submitted',
                // Program owner → funding setup of that application
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'mprv', [], [], $this->milestoneHash($data)),
            ],

            'mprv.approved' => [
                'title'         => 'MPRV Approved',
                'message'       => "Your MPRV for Milestone {$data['milestone_number']} has been approved",
                'email_subject' => 'MPRV Approved - Funds Ready for Disbursement',
                'email_view'    => $this->view_base . 'mprv_approved',
                // Entrepreneur → the MPRV tab of their deal room
                'link'          => $this->applicantDealroomLink($data, 'mprv'),
            ],

            'mprv.rejected' => [
                'title'         => 'MPRV Requires Changes',
                'message'       => "Your MPRV for Milestone {$data['milestone_number']} needs revision. Reason: {$data['reason']}",
                'email_subject' => 'MPRV Feedback Required',
                'email_view'    => $this->view_base . 'mprv_rejected',
                'link'          => $this->applicantDealroomLink($data, 'mprv'),
            ],

            'mprv.audit_requested' => [
                'title'         => 'Audit Requested',
                'message'       => "MPRV audit requested for {$data['business_name']} - Milestone {$data['milestone_number']}",
                'email_subject' => 'MPRV Audit Assignment',
                'email_view'    => $this->view_base . 'mprv_audit_requested',
                // PM/auditor → program audit page
                'link'          => $this->link('dashboard.serviceProvider.pmAudits.program'),
            ],

            'mprv.audit_completed' => [
                'title'         => 'Audit Completed',
                'message'       => "Project Manager completed audit for Milestone {$data['milestone_number']}",
                'email_subject' => 'MPRV Audit Complete',
                'email_view'    => $this->view_base . 'mprv_audit_completed',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'mprv', [], [], $this->milestoneHash($data)),
            ],

            // ── DISBURSEMENT ─────────────────────────────────────────────────
            'disbursement.created' => [
                'title'         => 'Payment Initiated',
                'message'       => "Program payment of {$data['amount']} USD initiated to {$data['supplier_name']}",
                'email_subject' => 'Payment Processing',
                'email_view'    => $this->view_base . 'disbursement_created',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'funding-setup', [], [], $this->milestoneHash($data)),
                'links'         => $this->dealroomLinks($data),
            ],

            'disbursement.supplier_processing' => [
                'title'         => 'Supplier Payment Processing',
                'message'       => "A payment of {$data['amount']} is being processed for {$data['supplier_name']}",
                'email_subject' => 'Your Payment Is on the Way',
                'email_view'    => $this->view_base . 'disbursement_supplier_processing',
                'link'          => 'overview/programs/supplier',
            ],

            'disbursement.completed' => [
                'title'         => 'Payment Completed',
                'message'       => "Payment of {$data['amount']} completed to {$data['supplier_name']}",
                'email_subject' => 'Payment Successful',
                'email_view'    => $this->view_base . 'disbursement_completed',
                'link'          => $this->applicantDealroomLink($data, 'plan', true),
                'links'         => $this->dealroomLinks($data),
            ],

            'disbursement.supplier_confirmed' => [
                'title'         => 'Funds Transferred',
                'message'       => "Payment of {$data['amount']} has been transferred to you for {$data['program_title']}",
                'email_subject' => 'Payment Transferred - Please Confirm Receipt',
                'email_view'    => $this->view_base . 'disbursement_supplier_confirmed',
                'link'          => 'overview/programs/supplier/confirm/' . ($data['disbursement_id'] ?? ''),
            ],

            'disbursement.failed' => [
                'title'         => 'Payment Failed',
                'message'       => "Payment to {$data['supplier_name']} failed. Reason: {$data['reason']}",
                'email_subject' => 'Payment Failed - Action Required',
                'email_view'    => $this->view_base . 'disbursement_failed',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'funding-setup', [], [], $this->milestoneHash($data)),
            ],

            'disbursement.reversed' => [
                'title'         => 'Payment Reversed',
                'message'       => "Payment of {$data['amount']} to {$data['supplier_name']} has been reversed",
                'email_subject' => 'Payment Reversal Notice',
                'email_view'    => $this->view_base . 'disbursement_reversed',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'funding-setup', [], [], $this->milestoneHash($data)),
                'links'         => $this->dealroomLinks($data),
            ],

            'milestone.funds_released' => [
                'title'         => 'Milestone Funds Released',
                'message'       => "All payments for Milestone {$data['milestone_number']} have been completed",
                'email_subject' => 'Milestone Funds Fully Disbursed',
                'email_view'    => $this->view_base . 'milestone_funds_released',
                'link'          => $this->applicantDealroomLink($data, 'plan', true),
            ],

            // ── DEAL ROOM ────────────────────────────────────────────────────
            'dealroom.document_uploaded' => [
                'title'         => 'New Document Uploaded',
                'message'       => "{$data['uploader_name']} uploaded {$data['document_type']} for Milestone {$data['milestone_number']}",
                'email_subject' => 'New Deal Room Document',
                'email_view'    => $this->view_base . 'dealroom_document_uploaded',
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'funding-setup', [], [], $this->milestoneHash($data)),
                'links'         => $this->dealroomLinks($data),
            ],

            // ── COMPLETION ───────────────────────────────────────────────────
            'completion.submitted' => [
                'title'         => 'Completion Submitted',
                'message'       => "{$data['business_name']} submitted completion for Milestone {$data['milestone_number']}",
                'email_subject' => 'Milestone Completion Submitted',
                'email_view'    => $this->view_base . 'completion_submitted',
                // Program owner → final approval step
                'link'          => $this->link('dashboard.programOrg.programDealroomDetail', $data, 'final-approval', [], [], $this->milestoneHash($data)),
            ],

            'completion.approved' => [
                'title'         => 'Milestone Complete! ✅',
                'message'       => "Your completion for Milestone {$data['milestone_number']} has been approved",
                'email_subject' => 'Milestone Approved',
                'email_view'    => $this->view_base . 'completion_approved',
                'link'          => $this->applicantDealroomLink($data, 'final'),
            ],

            'completion.rejected' => [
                'title'         => 'Completion Needs Revision',
                'message'       => "Your completion for Milestone {$data['milestone_number']} requires changes. Reason: {$data['reason']}",
                'email_subject' => 'Milestone Completion Feedback',
                'email_view'    => $this->view_base . 'completion_rejected',
                'link'          => $this->applicantDealroomLink($data, 'final'),
            ],

            'milestone.unlocked' => [
                'title'         => 'Next Milestone Unlocked',
                'message'       => "Milestone {$data['milestone_number']} is now unlocked. You can begin work!",
                'email_subject' => 'New Milestone Available',
                'email_view'    => $this->view_base . 'milestone_unlocked',
                'link'          => $this->applicantDealroomLink($data, 'plan', true),
            ],

            // ── WALLET ───────────────────────────────────────────────────────
            'wallet.deposited' => [
                'title'         => 'Funds Deposited',
                'message'       => "{$data['amount']} deposited to {$data['program_title']} wallet",
                'email_subject' => 'Program Wallet Funded',
                'email_view'    => $this->view_base . 'wallet_deposited',
                'link'          => $this->orgProgramLink($data + ['hash' => 'program-wallet']),
            ],

            'wallet.activated' => [
                'title'         => 'Wallet Activated',
                'message'       => "{$data['program_title']} wallet is now active and ready for disbursements",
                'email_subject' => 'Program Wallet Activated',
                'email_view'    => $this->view_base . 'wallet_activated',
                'link'          => $this->link('dashboard.programOrg.dealroom'),
            ],

            'wallet.low_balance' => [
                'title'         => 'Low Wallet Balance',
                'message'       => "{$data['program_title']} wallet balance is low: {$data['balance']}",
                'email_subject' => 'Program Wallet Low Balance Alert',
                'email_view'    => $this->view_base . 'wallet_low_balance',
                // TODO: deep link to wallet page when built
                'link'          => $this->orgDepositLink($data),
            ],

            // ── PROGRAM STATUS ─────────────────────────────────────────────────
            'program.published' => [
                'title'         => 'Program Published',
                'message'       => "{$data['program_title']} has been published",
                'email_subject' => 'Program Successfully Published',
                'email_view'    => $this->view_base . 'program_published',
                'link'          => $this->orgProgramLink($data),
            ],

            'program.opened' => [
                'title'         => 'Program Now Open',
                'message'       => "{$data['program_title']} is now accepting applications",
                'email_subject' => 'Program Applications Open',
                'email_view'    => $this->view_base . 'program_opened',
                'link'          => $this->orgProgramLink($data),
            ],

            'program.awarded' => [
                'title'         => 'Awardees Selected',
                'message'       => "All rounds completed for {$data['program_title']}. {$data['awarded_count']} awardees selected. Funding setup is ready to begin.",
                'email_subject' => 'Program Awardees Selected - Funding Setup Ready',
                'email_view'    => $this->view_base . 'program_awarded',
                // Program owner → deal room list
                'link'          => $this->orgAwardedLink($data),
            ],

            'program.closed' => [
                'title'         => 'Program Closed',
                'message'       => "{$data['program_title']} is no longer accepting applications",
                'email_subject' => 'Program Application Period Closed',
                'email_view'    => $this->view_base . 'program_closed',
                'link'          => $this->orgProgramLink($data),
            ],

            'program.finalized' => [
                'title'         => 'Program Complete',
                'message'       => "{$data['program_title']} has been finalized. {$data['awarded_count']} businesses funded.",
                'email_subject' => 'Program Program Complete',
                'email_view'    => $this->view_base . 'program_finalized',
                'link'          => $this->orgProgramLink($data),
            ],

            // ── REVIEWER EVENTS ──────────────────────────────────────────────────
            'reviewer.scoring_complete' => [
                'title'         => 'Round Scoring Complete',
                'message'       => "{$data['reviewer_name']} has completed scoring for {$data['round_name']}. Please review and finalize.",
                'email_subject' => 'Round Scoring Complete — Ready to Finalize',
                'email_view'    => $this->view_base . 'reviewer_scoring_complete',
                'link'          => $this->orgRoundLink($data + ['tab' => 'applications', 'hash' => 'round-applications']),
            ],

            'round.all_applications_scored' => [
                'title'         => 'Round Scoring Complete',
                'message'       => "All applications for {$data['round_name']} have been scored. Please finalize the round and start the next round.",
                'email_subject' => 'All Applications Scored — Finalize the Round',
                'email_view'    => $this->view_base . 'round_all_applications_scored',
                'link'          => $this->orgRoundLink($data + ['finalize' => true]),
            ],

            'reviewer.accepted' => [
                'title'         => 'Reviewer Accepted Assignment',
                'message'       => "{$data['reviewer_name']} accepted the review assignment for {$data['round_name']} in {$data['program_title']}.",
                'email_subject' => 'Reviewer Accepted Assignment',
                'email_view'    => $this->view_base . 'reviewer_accepted',
                'link'          => $this->orgRoundLink($data + ['tab' => 'reviewers', 'hash' => 'round-reviewers']),
            ],

            'reviewer.accepted_insufficient_funds' => [
                'title'         => 'Funds Required to Start Reviews',
                'message'       => "A reviewer accepted the assignment for {$data['round_name']} in {$data['program_title']}. Deposit {$data['shortfall']} to the program wallet to enable reviews.",
                'email_subject' => 'Deposit Funds to Start Program Reviews',
                'email_view'    => $this->view_base . 'reviewer_accepted_insufficient_funds',
                'link'          => $this->orgDepositLink($data),
            ],

            'reviewer.work_delivered' => [
                'title'         => 'Reviewer Work Submitted',
                'message'       => "{$data['reviewer_name']} has submitted their {$data['order_type']} work for {$data['program_title']}.",
                'email_subject' => 'Reviewer Work Submitted for Review',
                'email_view'    => $this->view_base . 'reviewer_work_delivered',
                'link'          => $this->orgOrdersLink('delivered', $data['order_id'] ?? null),
            ],

            'reviewer.modification_requested' => [
                'title'         => 'Modification Requested',
                'message'       => "A modification has been requested for your work on {$data['program_title']}.",
                'email_subject' => 'Modification Requested — Action Required',
                'email_view'    => $this->view_base . 'reviewer_modification_requested',
                'link'          => $this->reviewerOrdersLink($data),
            ],

            'reviewer.work_approved' => [
                'title'         => 'Work Approved 🎉',
                'message'       => "Your work on {$data['program_title']} has been approved. Payment of {$data['fee']} is being processed.",
                'email_subject' => 'Work Approved — Payment Processing',
                'email_view'    => $this->view_base . 'reviewer_work_approved',
                'link'          => $this->link('dashboard.reviewerGrantOrg.orders'),
            ],

            'reviewer.payment_initiated' => [
                'title'         => 'Payment Initiated',
                'message'       => "Payment for your work on {$data['round_name']} has been initiated.",
                'email_subject' => 'Payment Initiated',
                'email_view'    => $this->view_base . 'reviewer_payment_initiated',
                'link'          => $this->link('dashboard.reviewerGrantOrg.orders'),
            ],

            'reviewer.payment_completed' => [
                'title'         => 'Payment Received 💰',
                'message'       => "Payment of {$data['amount']} {$data['currency']} for {$data['program_title']} has been transferred to your wallet.",
                'email_subject' => 'Payment Received Successfully',
                'email_view'    => $this->view_base . 'reviewer_payment_completed',
                'link'          => $this->link('dashboard.reviewerGrantOrg.orders'),
            ],

            'reviewer.payment_failed' => [
                'title'         => 'Payment Failed',
                'message'       => "Payment to reviewer {$data['reviewer_name']} failed for {$data['program_title']}. Please retry.",
                'email_subject' => 'Reviewer Payment Failed — Action Required',
                'email_view'    => $this->view_base . 'reviewer_payment_failed',
                'link'          => $this->orgOrdersLink('completed', $data['order_id'] ?? null),
            ],

            'reviewer.declined' => [
                'title'         => 'Reviewer Declined Assignment',
                'message'       => "{$data['reviewer_name']} declined the review assignment for {$data['round_name']} in {$data['program_title']}.",
                'email_subject' => 'Reviewer Declined Assignment — Action Required',
                'email_view'    => $this->view_base . 'reviewer_declined',
                'link'          => $this->orgRoundLink($data + ['tab' => 'reviewers', 'hash' => 'round-reviewers']),
            ],

            default => throw new \InvalidArgumentException("Unknown event type: {$event}"),
        };
    }
}
