<?php
return [
    'grant' => [
        'draft' => 'info',
        'published' => 'warning',
        'open' => 'success',
        'closed' => 'danger',
        'awarded' => 'success',
    ],

    'grant_round' => [
        'draft'     => 'info',
        'published' => 'warning',
        'in_review' => 'warning',
        'closed'    => 'danger',
        'finalized' => 'success',
    ],

    'grant_application' => [
        'pending'   => 'info',
        'approved'  => 'success',
        'rejected'  => 'danger',
        'awarded'   => 'success',
        'completed' => 'success',
    ],

    'grant_application_round' => [
        'draft'        => 'info',
        'submitted'    => 'info',
        'under_review' => 'warning',
        'scored'       => 'warning',
        'advanced'     => 'success',
        'not_selected' => 'danger',
    ],

    'grant_milestone' => [
        'pending'     => 'info',
        'submitted'   => 'warning',
        'approved'    => 'success',
        'in_progress' => 'warning',
        'released'    => 'success',
        'completed'   => 'success',
    ],

    'milestone_fund_release' => [
        'pending'  => 'info',
        'approved' => 'success',
        'released' => 'success',
    ],

    'milestone_pre_agreement' => [
        'pending'        => 'warning',
        'agreed'         => 'success',
        'rejected'       => 'danger',
        'final_rejected' => 'danger',
        'not_started'    => 'info',
    ],

    'milestone_verification' => [        // MPRV
        'pending'         => 'info',
        'approved'        => 'success',
        'rejected'        => 'danger',
        'audit_requested' => 'warning',
    ],

    'disbursement' => [
        'pending'   => 'info',
        'completed' => 'success',
        'failed'    => 'danger',
        'reversed'  => 'warning',
    ],

    'milestone_completion' => [
        'pending'  => 'info',
        'approved' => 'success',
        'rejected' => 'danger',
    ],

    'funding_setup' => [                 // funding_setup_status on grant_applications
        'not_started'     => 'info',
        'in_progress'     => 'warning',
        'awaiting_review' => 'warning',
        'approved'        => 'success',
    ],

    'grant_wallet' => [
        'inactive' => 'danger',
        'active'   => 'success',
    ],

    'knockout' => [
        'pending' => 'info',
        'passed'  => 'success',
        'failed'  => 'danger',
    ],
];
