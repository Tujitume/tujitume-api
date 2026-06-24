<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Grant status
    |--------------------------------------------------------------------------
    */

    'grant-status' => [
        [
            'value' => 'draft',
            'color' => 'info',
        ],
        [
            'value' => 'published',
            'color' => 'warning',
        ],
        [
            'value' => 'open',
            'label' => 'Open',
            'color' => 'success',
        ],
        [
            'value' => 'closed',
            'label' => 'Closed',
            'color' => 'danger',
        ],
        [
            'value' => 'awarded',
            'label' => 'Awarded',
            'color' => 'success',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Grant Round status
    |--------------------------------------------------------------------------
    */

    'grant-round-status' => [
        [
            'value' => 'draft',
            'color' => 'info',
        ],
        [
            'value' => 'published',
            'color' => 'warning',
        ],
        [
            'value' => 'in_review',
            'label' => 'In Review',
            'color' => 'warning',
        ],
        [
            'value' => 'closed',
            'label' => 'Closed',
            'color' => 'danger',
        ],
        [
            'value' => 'finalized',
            'label' => 'Finalized',
            'color' => 'success',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Grant Application status
    |--------------------------------------------------------------------------
    */

    'grant-application-status' => [
        [
            'value' => 'pending',
            'label' => 'Pending',
            'color' => 'info',
        ],
        [
            'value' => 'approved',
            'label' => 'Approved',
            'color' => 'success',
        ],
        [
            'value' => 'rejected',
            'label' => 'Rejected',
            'color' => 'danger',
        ],
        [
            'value' => 'awarded',
            'label' => 'Awarded',
            'color' => 'success',
        ],
        [
            'value' => 'completed',
            'label' => 'Completed',
            'color' => 'success',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Grant Application Round status
    |--------------------------------------------------------------------------
    */

    'grant-application-round-status' => [
        [
            'value' => 'draft',
            'color' => 'info',
        ],
        [
            'value' => 'submitted',
            'label' => 'Submitted',
            'color' => 'info',
        ],
        [
            'value' => 'under_review',
            'label' => 'Under Review',
            'color' => 'warning',
        ],
        [
            'value' => 'scored',
            'label' => 'Scored',
            'color' => 'warning',
        ],
        [
            'value' => 'advanced',
            'label' => 'Advanced',
            'color' => 'success',
        ],
        [
            'value' => 'not_selected',
            'label' => 'Not Selected',
            'color' => 'danger',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Grant Milestone status
    |--------------------------------------------------------------------------
    */

    'grant-milestone-status' => [
        [
            'value' => 'pending',
            'label' => 'Pending',
            'color' => 'info',
        ],
        [
            'value' => 'submitted',
            'label' => 'Submitted',
            'color' => 'warning',
        ],
        [
            'value' => 'approved',
            'label' => 'Approved',
            'color' => 'success',
        ],
        [
            'value' => 'in_progress',
            'label' => 'In Progress',
            'color' => 'warning',
        ],
        [
            'value' => 'released',
            'label' => 'Released',
            'color' => 'success',
        ],
        [
            'value' => 'completed',
            'label' => 'Completed',
            'color' => 'success',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Milestone Fund Release status
    |--------------------------------------------------------------------------
    */

    'milestone-fund-release-status' => [
        [
            'value' => 'pending',
            'color' => 'info',
        ],
        [
            'value' => 'approved',
            'color' => 'success',
        ],
        [
            'value' => 'released',
            'color' => 'success',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Milestone Verification status (MPRV)
    |--------------------------------------------------------------------------
    */

    'milestone-verification-status' => [
        [
            'value' => 'pending',
            'color' => 'info',
        ],
        [
            'value' => 'approved',
            'label' => 'Approved',
            'color' => 'success',
        ],
        [
            'value' => 'rejected',
            'label' => 'Rejected',
            'color' => 'danger',
        ],
        [
            'value' => 'audit_requested',
            'label' => 'Audit Requested',
            'color' => 'warning',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Disbursement status
    |--------------------------------------------------------------------------
    */

    'disbursement-status' => [
        [
            'value' => 'pending',
            'label' => 'Pending',
            'color' => 'info',
        ],
        [
            'value' => 'completed',
            'label' => 'Completed',
            'color' => 'success',
        ],
        [
            'value' => 'failed',
            'label' => 'Failed',
            'color' => 'danger',
        ],
        [
            'value' => 'reversed',
            'label' => 'Reversed',
            'color' => 'warning',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Milestone Completion status
    |--------------------------------------------------------------------------
    */

    'milestone-completion-status' => [
        [
            'value' => 'pending',
            'label' => 'Pending',
            'color' => 'info',
        ],
        [
            'value' => 'approved',
            'label' => 'Approved',
            'color' => 'success',
        ],
        [
            'value' => 'rejected',
            'label' => 'Rejected',
            'color' => 'danger',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Funding Setup status
    |--------------------------------------------------------------------------
    */

    'funding-setup-status' => [
        [
            'value' => 'not_started',
            'label' => 'Not Started',
            'color' => 'info',
        ],
        [
            'value' => 'in_progress',
            'label' => 'In Progress',
            'color' => 'warning',
        ],
        [
            'value' => 'awaiting_review',
            'label' => 'Awaiting Review',
            'color' => 'warning',
        ],
        [
            'value' => 'approved',
            'label' => 'Approved',
            'color' => 'success',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Grant Wallet status
    |--------------------------------------------------------------------------
    */

    'grant-wallet-status' => [
        [
            'value' => 'inactive',
            'label' => 'Inactive',
            'color' => 'danger',
        ],
        [
            'value' => 'active',
            'label' => 'Active',
            'color' => 'success',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Knockout status
    |--------------------------------------------------------------------------
    */

    'knockout-status' => [
        [
            'value' => 'pending',
            'label' => 'Pending',
            'color' => 'info',
        ],
        [
            'value' => 'passed',
            'label' => 'Passed',
            'color' => 'success',
        ],
        [
            'value' => 'failed',
            'label' => 'Failed',
            'color' => 'danger',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Applicant Editable Milestone Fields
    |--------------------------------------------------------------------------
    */

    'milestone-allowed-edit-fields' => [
        'title',
        'description',
        'expected_outcomes',
        'document',
        'estimated_completion_date',
        "can_add_suppliers",
        "can_add_budget_items"
    ],

];

// future improvements
//'colors'
//'labels'
//'permissions'
//'editable_by'
//'icons'
//'workflow_steps'
