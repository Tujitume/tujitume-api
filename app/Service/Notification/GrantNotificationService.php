<?php
namespace App\Service\Notification;
use App\Models\Communication\Notifications;
use App\Models\GrantEmailTemplate;
use Illuminate\Support\Facades\Log;

class GrantNotificationService
{
    protected EmailService $emailService;
    protected NotificationService $notification;

    public function __construct() {
        $this->emailService = new EmailService();
        $this->notification = new NotificationService();
        $this->view_base    = 'grants.';
    }

    /**
     * Send notification + email
     */
    public function send(string $event, array $recipients, array $data = []): void
    {
        $config = $this->getEventConfig($event, $data);
        $type = in_array($event, ['wallet.deposited']) ? 'deposit' : 'grant';

        $customBody = null;
        if (!empty($data['grant_id']) && in_array($event, GrantEmailTemplate::CUSTOMISABLE_EVENTS)) {
            $customBody = GrantEmailTemplate::resolve((int) $data['grant_id'], $event);
        }

        foreach ($recipients as $recipient) {
            if (!$recipient) continue;

            $this->notification->create(
                $recipient->id, $recipient->id, $config['message'], $config['link'], $type,
            );

            $this->emailService->send(
                $config['email_subject'],
                $config['email_view'],
                array_merge($data, [
                    'recipientName'  => $recipient->fname ?? $recipient->name,
                    'recipientEmail' => $recipient->email,
                    'custom_body'    => $customBody,
                ]),
                $recipient->email
            );
        }
    }

    /**
     * Build a dot-notation link key.
     * The frontend notificationLinkResolver.js maps these to real paths.
     *
     * Format:  routeName[::appId][::step]
     * Example: dashboard.grantOrg.grantDealroomDetail::61::funding-setup
     *          dashboard.entrepreneur.grantsDealroom.detail::61
     *
     * The frontend splits on '::' to extract the route name + params.
     */
    private function link(string $routeName, array $data = [], string $step = ''): string
    {
        $appId = $data['application_id'] ?? null;
        $parts = [$routeName];
        if ($appId)  $parts[] = (string) $appId;
        if ($step)   $parts[] = $step;
        return implode('::', $parts);
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
                'message'       => "{$data['business_name']} has submitted an application to {$data['grant_title']}",
                'email_subject' => 'New Grant Application Submitted',
                'email_view'    => $this->view_base . 'application_submitted',
                // Grant owner → applications list
                'link'          => $this->link('dashboard.grantOrg.applications'),
            ],

            'application.accepted' => [
                'title'         => 'Application Accepted',
                'message'       => "Your application to {$data['grant_title']} has been accepted!",
                'email_subject' => 'Congratulations! Your Application Was Accepted',
                'email_view'    => $this->view_base . 'application_accepted',
                // Entrepreneur → their grants dealroom
                'link'          => $this->link('dashboard.entrepreneur.grantsDealroom'),
            ],

            'application.rejected' => [
                'title'         => 'Application Update',
                'message'       => "Your application to {$data['grant_title']} was not selected",
                'email_subject' => 'Grant Application Update',
                'email_view'    => $this->view_base . 'application_rejected',
                'link'          => $this->link('dashboard.entrepreneur.grantsApplication'),
            ],

            // ── ROUNDS ───────────────────────────────────────────────────────
            'round.opened' => [
                'title'         => 'New Round Open',
                'message'       => "{$data['round_name']} is now open for {$data['grant_title']}",
                'email_subject' => 'New Application Round Open',
                'email_view'    => $this->view_base . 'round_opened',
                'link'          => $this->link('dashboard.entrepreneur.grantsDiscover'),
            ],

            'round.closing_soon' => [
                'title'         => 'Round Closing Soon',
                'message'       => "{$data['round_name']} closes in {$data['days_left']} days",
                'email_subject' => 'Application Deadline Approaching',
                'email_view'    => $this->view_base . 'round_closing_soon',
                'link'          => $this->link('dashboard.entrepreneur.grantsApplication'),
            ],

            'round.closed' => [
                'title'         => 'Round Closed',
                'message'       => "{$data['round_name']} is now closed for submissions",
                'email_subject' => 'Application Round Closed',
                'email_view'    => $this->view_base . 'round_closed',
                'link'          => $this->link('dashboard.entrepreneur.grantsApplication'),
            ],

            'round.advanced' => [
                'title'         => 'Advanced to Next Round',
                'message'       => "Congratulations! You've advanced to {$data['round_name']} in {$data['grant_title']}",
                'email_subject' => 'You Advanced to the Next Round!',
                'email_view'    => $this->view_base . 'round_advanced',
                // Entrepreneur → their specific deal room if we have app_id
                'link'          => $this->link('dashboard.entrepreneur.grantsDealroom.detail', $data),
            ],

            'round.not_selected' => [
                'title'         => 'Round Update',
                'message'       => "Sorry your application was rejected, thank you for your participation in {$data['round_name']}",
                'email_subject' => 'Grant Round Update',
                'email_view'    => $this->view_base . 'round_not_selected',
                'link'          => $this->link('dashboard.entrepreneur.grantsApplication'),
            ],

            'round.scoring_assigned' => [
                'title'         => 'New Applications to Review',
                'message'       => "You have {$data['count']} applications to review for {$data['grant_title']}",
                'email_subject' => 'Applications Assigned for Review',
                'email_view'    => $this->view_base . 'reviewer_assigned',
                // TODO: deep link to round review page when built
                'link'          => $this->link('dashboard.grantOrg.applications'),
            ],

            'round.score_received' => [
                'title'         => 'Application Reviewed',
                'message'       => "Your application for {$data['grant_title']} has been reviewed",
                'email_subject' => 'Application Review Update',
                'email_view'    => $this->view_base . 'score_received',
                // TODO: deep link to application detail when built
                'link'          => $this->link('dashboard.entrepreneur.grantsApplication'),
            ],

            // ── AWARD ────────────────────────────────────────────────────────
            'application.awarded' => [
                'title'         => 'Grant Awarded! 🎉',
                'message'       => "Congratulations! You've been awarded {$data['amount']} from {$data['grant_title']}",
                'email_subject' => 'Congratulations! Grant Awarded',
                'email_view'    => $this->view_base . 'application_awarded',
                'link'          => $this->link('dashboard.entrepreneur.grantsDealroom'),
            ],

            'milestones.created' => [
                'title'         => 'Milestones Created',
                'message'       => "{$data['milestone_count']} milestones created for {$data['grant_title']}",
                'email_subject' => 'Your Grant Milestones',
                'email_view'    => $this->view_base . 'milestones_created',
                'link'          => $this->link('dashboard.entrepreneur.grantsDealroom'),
            ],

            // ── SUPPLIER & BUDGET ────────────────────────────────────────────
            'supplier.added' => [
                'title'         => 'Supplier Added',
                'message'       => "Supplier {$data['supplier_name']} added to Milestone {$data['milestone_number']}",
                'email_subject' => 'Supplier Information Added',
                'email_view'    => $this->view_base . 'supplier_added',
                'link'          => $this->link('dashboard.grantOrg.grantDealroomDetail', $data, 'funding-setup'),
            ],

            'budget.completed' => [
                'title'         => 'Budget Ready',
                'message'       => "Budget completed for Milestone {$data['milestone_number']}. Ready to submit MPRV.",
                'email_subject' => 'Ready to Submit MPRV',
                'email_view'    => $this->view_base . 'budget_completed',
                'link'          => $this->link('dashboard.entrepreneur.grantsDealroom.detail', $data),
            ],

            // ── MPRV ─────────────────────────────────────────────────────────
            'mprv.submitted' => [
                'title'         => 'MPRV Submitted for Review',
                'message'       => "{$data['business_name']} submitted MPRV for Milestone {$data['milestone_number']}",
                'email_subject' => 'New MPRV Submitted',
                'email_view'    => $this->view_base . 'mprv_submitted',
                // Grant owner → funding setup of that application
                'link'          => $this->link('dashboard.grantOrg.grantDealroomDetail', $data, 'funding-setup'),
            ],

            'mprv.approved' => [
                'title'         => 'MPRV Approved',
                'message'       => "Your MPRV for Milestone {$data['milestone_number']} has been approved",
                'email_subject' => 'MPRV Approved - Funds Ready for Disbursement',
                'email_view'    => $this->view_base . 'mprv_approved',
                // Entrepreneur → their deal room
                'link'          => $this->link('dashboard.entrepreneur.grantsDealroom.detail', $data),
            ],

            'mprv.rejected' => [
                'title'         => 'MPRV Requires Changes',
                'message'       => "Your MPRV for Milestone {$data['milestone_number']} needs revision. Reason: {$data['reason']}",
                'email_subject' => 'MPRV Feedback Required',
                'email_view'    => $this->view_base . 'mprv_rejected',
                'link'          => $this->link('dashboard.entrepreneur.grantsDealroom.detail', $data),
            ],

            'mprv.audit_requested' => [
                'title'         => 'Audit Requested',
                'message'       => "MPRV audit requested for {$data['business_name']} - Milestone {$data['milestone_number']}",
                'email_subject' => 'MPRV Audit Assignment',
                'email_view'    => $this->view_base . 'mprv_audit_requested',
                // PM/auditor → grant audit page
                'link'          => $this->link('dashboard.serviceProvider.pmAudits.grant'),
            ],

            'mprv.audit_completed' => [
                'title'         => 'Audit Completed',
                'message'       => "Project Manager completed audit for Milestone {$data['milestone_number']}",
                'email_subject' => 'MPRV Audit Complete',
                'email_view'    => $this->view_base . 'mprv_audit_completed',
                'link'          => $this->link('dashboard.grantOrg.grantDealroomDetail', $data, 'funding-setup'),
            ],

            // ── DISBURSEMENT ─────────────────────────────────────────────────
            'disbursement.created' => [
                'title'         => 'Payment Initiated',
                'message'       => "Grant payment of {$data['amount']} USD initiated to {$data['supplier_name']}",
                'email_subject' => 'Payment Processing',
                'email_view'    => $this->view_base . 'disbursement_created',
                'link'          => $this->link('dashboard.grantOrg.grantDealroomDetail', $data, 'funding-setup'),
            ],

            'disbursement.completed' => [
                'title'         => 'Payment Completed',
                'message'       => "Payment of {$data['amount']} completed to {$data['supplier_name']}",
                'email_subject' => 'Payment Successful',
                'email_view'    => $this->view_base . 'disbursement_completed',
                'link'          => $this->link('dashboard.entrepreneur.grantsDealroom.detail', $data),
            ],

            'disbursement.supplier_confirmed' => [
                'title'         => 'Funds Transferred',
                'message'       => "Payment of {$data['amount']} has been transferred to you for {$data['grant_title']}",
                'email_subject' => 'Payment Transferred - Please Confirm Receipt',
                'email_view'    => $this->view_base . 'disbursement_supplier_confirmed',
                'link'          => 'overview/grants/supplier/confirm/' . ($data['disbursement_id'] ?? ''),
            ],

            'disbursement.failed' => [
                'title'         => 'Payment Failed',
                'message'       => "Payment to {$data['supplier_name']} failed. Reason: {$data['reason']}",
                'email_subject' => 'Payment Failed - Action Required',
                'email_view'    => $this->view_base . 'disbursement_failed',
                'link'          => $this->link('dashboard.grantOrg.grantDealroomDetail', $data, 'funding-setup'),
            ],

            'disbursement.reversed' => [
                'title'         => 'Payment Reversed',
                'message'       => "Payment of {$data['amount']} to {$data['supplier_name']} has been reversed",
                'email_subject' => 'Payment Reversal Notice',
                'email_view'    => $this->view_base . 'disbursement_reversed',
                'link'          => $this->link('dashboard.grantOrg.grantDealroomDetail', $data, 'funding-setup'),
            ],

            'milestone.funds_released' => [
                'title'         => 'Milestone Funds Released',
                'message'       => "All payments for Milestone {$data['milestone_number']} have been completed",
                'email_subject' => 'Milestone Funds Fully Disbursed',
                'email_view'    => $this->view_base . 'milestone_funds_released',
                'link'          => $this->link('dashboard.entrepreneur.grantsDealroom.detail', $data),
            ],

            // ── DEAL ROOM ────────────────────────────────────────────────────
            'dealroom.document_uploaded' => [
                'title'         => 'New Document Uploaded',
                'message'       => "{$data['uploader_name']} uploaded {$data['document_type']} for Milestone {$data['milestone_number']}",
                'email_subject' => 'New Deal Room Document',
                'email_view'    => $this->view_base . 'dealroom_document_uploaded',
                'link'          => $this->link('dashboard.grantOrg.grantDealroomDetail', $data, 'funding-setup'),
            ],

            // ── COMPLETION ───────────────────────────────────────────────────
            'completion.submitted' => [
                'title'         => 'Completion Submitted',
                'message'       => "{$data['business_name']} submitted completion for Milestone {$data['milestone_number']}",
                'email_subject' => 'Milestone Completion Submitted',
                'email_view'    => $this->view_base . 'completion_submitted',
                // Grant owner → final approval step
                'link'          => $this->link('dashboard.grantOrg.grantDealroomDetail', $data, 'final-approval'),
            ],

            'completion.approved' => [
                'title'         => 'Milestone Complete! ✅',
                'message'       => "Your completion for Milestone {$data['milestone_number']} has been approved",
                'email_subject' => 'Milestone Approved',
                'email_view'    => $this->view_base . 'completion_approved',
                'link'          => $this->link('dashboard.entrepreneur.grantsDealroom.detail', $data),
            ],

            'completion.rejected' => [
                'title'         => 'Completion Needs Revision',
                'message'       => "Your completion for Milestone {$data['milestone_number']} requires changes. Reason: {$data['reason']}",
                'email_subject' => 'Milestone Completion Feedback',
                'email_view'    => $this->view_base . 'completion_rejected',
                'link'          => $this->link('dashboard.entrepreneur.grantsDealroom.detail', $data),
            ],

            'milestone.unlocked' => [
                'title'         => 'Next Milestone Unlocked',
                'message'       => "Milestone {$data['milestone_number']} is now unlocked. You can begin work!",
                'email_subject' => 'New Milestone Available',
                'email_view'    => $this->view_base . 'milestone_unlocked',
                'link'          => $this->link('dashboard.entrepreneur.grantsDealroom.detail', $data),
            ],

            // ── WALLET ───────────────────────────────────────────────────────
            'wallet.deposited' => [
                'title'         => 'Funds Deposited',
                'message'       => "{$data['amount']} deposited to {$data['grant_title']} wallet",
                'email_subject' => 'Grant Wallet Funded',
                'email_view'    => $this->view_base . 'wallet_deposited',
                'link'          => $this->link('dashboard.grantOrg.account'),
            ],

            'wallet.activated' => [
                'title'         => 'Wallet Activated',
                'message'       => "{$data['grant_title']} wallet is now active and ready for disbursements",
                'email_subject' => 'Grant Wallet Activated',
                'email_view'    => $this->view_base . 'wallet_activated',
                'link'          => $this->link('dashboard.grantOrg.dealroom'),
            ],

            'wallet.low_balance' => [
                'title'         => 'Low Wallet Balance',
                'message'       => "{$data['grant_title']} wallet balance is low: {$data['balance']}",
                'email_subject' => 'Grant Wallet Low Balance Alert',
                'email_view'    => $this->view_base . 'wallet_low_balance',
                // TODO: deep link to wallet page when built
                'link'          => $this->link('dashboard.grantOrg.dealroom'),
            ],

            // ── GRANT STATUS ─────────────────────────────────────────────────
            'grant.published' => [
                'title'         => 'Grant Published',
                'message'       => "{$data['grant_title']} has been published",
                'email_subject' => 'Grant Successfully Published',
                'email_view'    => $this->view_base . 'grant_published',
                'link'          => $this->link('dashboard.grantOrg.grantsDiscover'),
            ],

            'grant.opened' => [
                'title'         => 'Grant Now Open',
                'message'       => "{$data['grant_title']} is now accepting applications",
                'email_subject' => 'Grant Applications Open',
                'email_view'    => $this->view_base . 'grant_opened',
                'link'          => $this->link('dashboard.entrepreneur.grantsDiscover'),
            ],

            'grant.awarded' => [
                'title'         => 'Awardees Selected',
                'message'       => "All rounds completed for {$data['grant_title']}. {$data['awarded_count']} awardees selected. Funding setup is ready to begin.",
                'email_subject' => 'Grant Awardees Selected - Funding Setup Ready',
                'email_view'    => $this->view_base . 'grant_awarded',
                // Grant owner → deal room list
                'link'          => $this->link('dashboard.grantOrg.dealroom'),
            ],

            'grant.closed' => [
                'title'         => 'Grant Closed',
                'message'       => "{$data['grant_title']} is no longer accepting applications",
                'email_subject' => 'Grant Application Period Closed',
                'email_view'    => $this->view_base . 'grant_closed',
                'link'          => $this->link('dashboard.grantOrg.grantsDiscover'),
            ],

            'grant.finalized' => [
                'title'         => 'Grant Complete',
                'message'       => "{$data['grant_title']} has been finalized. {$data['awarded_count']} businesses funded.",
                'email_subject' => 'Grant Program Complete',
                'email_view'    => $this->view_base . 'grant_finalized',
                'link'          => $this->link('dashboard.grantOrg.grantsDiscover'),
            ],

            default => throw new \InvalidArgumentException("Unknown event type: {$event}"),
        };
    }
}
