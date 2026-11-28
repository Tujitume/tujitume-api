<?php

/**
 * The colour of every status the API sends (DECISION-7, owner 27 Nov). One meaning per colour:
 *   success  done, or a good result          warning  waiting for someone, or in progress
 *   danger   failed, refused or stopped      info     new, or sent
 *   neutral  not started, inactive, or ended normally
 * Every value of each status column is listed (tests/Feature/StatusColoursTest.php fails when one is missing), and
 * nothing else: ApiContract::statusMeta() builds {value, label, color} from here.
 */
return [
    'program' => [ // programs.status
        'draft' => 'neutral', 'published' => 'success', 'open' => 'success', 'under_review' => 'warning',
        'closed' => 'neutral', 'awarded' => 'success', 'completed' => 'success', 'cancelled' => 'danger',
    ],

    'program_round' => [ // program_rounds.status
        'draft' => 'neutral', 'published' => 'success', 'in_review' => 'warning', 'closed' => 'neutral', 'finalized' => 'success',
    ],

    'program_application' => [ // program_applications.status
        'draft' => 'neutral', 'pending' => 'info', 'under_review' => 'warning', 'waitlisted' => 'warning',
        'approved' => 'success', 'awarded' => 'success', 'in_monitoring' => 'warning', 'completed' => 'success',
        'rejected' => 'danger', 'withdrawn' => 'neutral',
    ],

    'program_application_round' => [ // program_applications.round_status
        'draft' => 'neutral', 'submitted' => 'info', 'assigned' => 'warning', 'under_review' => 'warning', 'scored' => 'warning',
        'advanced' => 'success', 'not_selected' => 'danger', 'ineligible' => 'danger', 'withdrawn' => 'neutral',
    ],

    'program_milestone' => [ // program_milestones.status
        'pending' => 'neutral', 'submitted' => 'info', 'under_review' => 'warning', 'audit_requested' => 'warning',
        'revision_requested' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'disbursing' => 'warning',
        'completed' => 'success', 'completion_approved' => 'success', 'completion_rejected' => 'danger',
    ],

    'milestone_fund_release' => [ // program_milestones.fund_release_status
        'locked' => 'neutral', 'approved' => 'success', 'processing' => 'warning', 'partial' => 'warning', 'released' => 'success',
    ],

    'milestone_pre_agreement' => [ // milestone_pre_agreements.status
        'pending' => 'neutral', 'submitted' => 'info', 'agreed' => 'success', 'rejected' => 'danger', 'final_rejected' => 'danger',
    ],

    'milestone_verification' => [ // milestone_verifications.decision (MPRV, mid-milestone, final approval)
        'pending' => 'warning', 'audit_requested' => 'warning', 'modification_requested' => 'warning',
        'approved' => 'success', 'rejected' => 'danger',
    ],

    'disbursement' => [ // disbursements.status
        'pending' => 'warning', 'processing' => 'warning', 'completed' => 'success', 'failed' => 'danger', 'reversed' => 'danger',
    ],

    'milestone_completion' => [ // milestone_completion_submissions.decision
        'pending' => 'warning', 'audit_requested' => 'warning', 'approved' => 'success', 'rejected' => 'danger',
    ],

    'funding_setup' => [ // program_applications.funding_setup_status
        'not_started' => 'neutral', 'in_progress' => 'warning', 'awaiting_applicant_revision' => 'warning',
        'awaiting_owner_review' => 'warning', 'revision_requested' => 'warning', 'completed' => 'success',
    ],

    'program_wallet' => [ // program_wallets.status
        'inactive' => 'neutral', 'active' => 'success', 'frozen' => 'danger', 'closed' => 'neutral',
    ],

    'knockout' => [ // program_applications.knockout_status
        'pending' => 'neutral', 'passed' => 'success', 'failed' => 'danger',
    ],

    'me_checkpoint' => [ // m_e_checkpoints.status
        'pending' => 'neutral', 'submitted' => 'info', 'changes_requested' => 'warning', 'verified' => 'success',
    ],

    'me_site_visit' => [ // m_e_site_visits.status
        'scheduled' => 'info', 'completed' => 'success', 'cancelled' => 'danger',
    ],

    'reviewer_order' => [ // reviewer_orders.work_status
        'pending' => 'neutral', 'assigned' => 'warning', 'in_progress' => 'warning', 'delivered' => 'info',
        'modification_requested' => 'warning', 'approved' => 'success', 'rejected' => 'danger',
    ],

    'reviewer_payment' => [ // reviewer_orders.payment_status
        'unpaid' => 'neutral', 'pending' => 'warning', 'leg1_processing' => 'warning', 'completed' => 'success', 'failed' => 'danger',
    ],

    // An investor's bid or investment in a business (My Investments): DECISION-7 rule
    'investment' => [
        'pending'            => 'info',     // bid made, waiting for the owner
        'awaiting_payment'   => 'warning',  // accepted, the remaining 75% to pay
        'under_verification' => 'warning',  // asset accepted, being verified
        'confirmed'          => 'success',
        'verified'           => 'success',
        'equipment_released' => 'success',
    ],
];
