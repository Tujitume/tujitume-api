<?php

namespace App\Service\Business\Milestone;

use App\Models\Milestones\Milestones;

class MilestoneWorkflowHelper
{
    /**
     * Workflow phases constants
     */
    const PHASE_FUNDING = 'funding';
    const PHASE_RMEP_VOTING = 'rmep_voting';
    const PHASE_PRE_RELEASE = 'pre_release';
    const PHASE_MID_MILESTONE = 'mid_milestone';
    const PHASE_FINAL_APPROVAL = 'final_approval';
    const PHASE_COMPLETED = 'completed';
    const PHASE_NON_COMPLIANT = 'non_compliant';

    /**
     * Milestone statuses
     */
    const STATUS_LOCKED = 'locked';
    const STATUS_TO_DO = 'to_do';
    const STATUS_AT_RISK = 'at_risk';
    const STATUS_FULLY_FUNDED = 'fully_funded';
    const STATUS_CONTINUATION_TRIGGERED = 'continuation_triggered';
    const STATUS_RMEP_SUBMITTED = 'rmep_submitted';
    const STATUS_PRE_RELEASE_REQUESTED = 'pre_release_requested';
    const STATUS_IN_PR_AUDIT = 'in_pr_audit';
    const STATUS_PR_APPROVED = 'pr_approved';
    const STATUS_PR_REJECTED = 'pr_rejected';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_MID_MILESTONE_SUBMITTED = 'mid_milestone_submitted';
    const STATUS_IN_MID_AUDIT = 'in_mid_audit';
    const STATUS_EXECUTION_SUBMITTED = 'execution_submitted';
    const STATUS_IN_FINAL_AUDIT = 'in_final_audit';
    const STATUS_DONE = 'done';
    const STATUS_NON_COMPLIANT = 'non_compliant';
    const STATUS_ADMIN_REVIEW = 'admin_review';

    /**
     * Get the current workflow phase for a milestone
     */
    public static function getCurrentPhase(Milestones $milestone): string
    {
        // Non-compliant overrides everything
        if ($milestone->status === self::STATUS_NON_COMPLIANT) {
            return self::PHASE_NON_COMPLIANT;
        }

        // Completed milestone
        if ($milestone->status === self::STATUS_DONE) {
            return self::PHASE_COMPLETED;
        }

        // Final Approval Phase
        if ($milestone->final_approval_started ||
            $milestone->status === self::STATUS_EXECUTION_SUBMITTED ||
            $milestone->status === self::STATUS_IN_FINAL_AUDIT) {
            return self::PHASE_FINAL_APPROVAL;
        }

        // Mid-Milestone Verification Phase
        if ($milestone->mid_milestone_started ||
            $milestone->status === self::STATUS_MID_MILESTONE_SUBMITTED ||
            $milestone->status === self::STATUS_IN_MID_AUDIT) {
            return self::PHASE_MID_MILESTONE;
        }

        // Pre-Release Phase
        if ($milestone->fund_released_75 || $milestone->status === self::STATUS_IN_PROGRESS) {
            return self::PHASE_MID_MILESTONE; // After pre-release approval, goes to mid-milestone
        }

        if ($milestone->is_funded && (
            $milestone->status === self::STATUS_PRE_RELEASE_REQUESTED ||
            $milestone->status === self::STATUS_IN_PR_AUDIT ||
            $milestone->status === self::STATUS_PR_APPROVED)) {
            return self::PHASE_PRE_RELEASE;
        }

        // RMEP & Voting Phase
        if ($milestone->status === self::STATUS_CONTINUATION_TRIGGERED ||
            $milestone->status === self::STATUS_RMEP_SUBMITTED) {
            return self::PHASE_RMEP_VOTING;
        }

        // Funding Phase (default for active milestones)
        return self::PHASE_FUNDING;
    }

    /**
     * Check if a milestone phase is complete
     */
    public static function isPhaseComplete(Milestones $milestone, string $phase): bool
    {
        switch ($phase) {
            case self::PHASE_FUNDING:
                return $milestone->progress_percentage >= 100 && $milestone->is_funded;

            case self::PHASE_RMEP_VOTING:
                return $milestone->continuation_approved === 1 ||
                       $milestone->rmep_approved > $milestone->rmep_rejected;

            case self::PHASE_PRE_RELEASE:
                return $milestone->fund_released_75 === 1 &&
                       $milestone->pr_approved > $milestone->pr_rejected;

            case self::PHASE_MID_MILESTONE:
                return $milestone->status === self::STATUS_IN_PROGRESS &&
                       $milestone->mid_milestone_started === 1;

            case self::PHASE_FINAL_APPROVAL:
                return $milestone->status === self::STATUS_DONE;

            default:
                return false;
        }
    }

    /**
     * Get next required action for business owner
     */
    public static function getBusinessOwnerNextAction(Milestones $milestone): array
    {
        $currentPhase = self::getCurrentPhase($milestone);

        switch ($currentPhase) {
            case self::PHASE_FUNDING:
                if ($milestone->progress_percentage < 100) {
                    return [
                        'action' => 'promote_milestone',
                        'message' => 'Continue promoting your milestone to reach 100% funding',
                        'urgent' => $milestone->status === self::STATUS_AT_RISK
                    ];
                }
                return [
                    'action' => 'wait_for_investors',
                    'message' => 'Milestone is fully funded. Waiting for investor pre-release requests.',
                    'urgent' => false
                ];

            case self::PHASE_RMEP_VOTING:
                if ($milestone->status === self::STATUS_CONTINUATION_TRIGGERED) {
                    return [
                        'action' => 'submit_rmep',
                        'message' => 'Submit a Revised Milestone Execution Plan (RMEP) to address the funding gap',
                        'urgent' => true
                    ];
                }
                return [
                    'action' => 'wait_for_voting',
                    'message' => 'RMEP submitted. Waiting for investor votes.',
                    'urgent' => false
                ];

            case self::PHASE_PRE_RELEASE:
                if ($milestone->status === self::STATUS_PRE_RELEASE_REQUESTED) {
                    return [
                        'action' => 'upload_pre_release_docs',
                        'message' => 'Upload required pre-release documentation as requested by investors',
                        'urgent' => true
                    ];
                }
                return [
                    'action' => 'wait_for_approval',
                    'message' => 'Pre-release documents submitted. Waiting for investor approval.',
                    'urgent' => false
                ];

            case self::PHASE_MID_MILESTONE:
                return [
                    'action' => 'execute_milestone',
                    'message' => 'Execute your milestone plan and prepare mid-milestone verification documents',
                    'urgent' => false
                ];

            case self::PHASE_FINAL_APPROVAL:
                if ($milestone->status === self::STATUS_IN_PROGRESS) {
                    return [
                        'action' => 'submit_final_proof',
                        'message' => 'Submit final execution proof and completion documentation',
                        'urgent' => true
                    ];
                }
                return [
                    'action' => 'wait_for_final_approval',
                    'message' => 'Final proof submitted. Waiting for investor final approval.',
                    'urgent' => false
                ];

            case self::PHASE_NON_COMPLIANT:
                return [
                    'action' => 'resolve_non_compliance',
                    'message' => 'Address non-compliance issues immediately to avoid project termination',
                    'urgent' => true
                ];

            default:
                return [
                    'action' => 'no_action',
                    'message' => 'Milestone is complete',
                    'urgent' => false
                ];
        }
    }

    /**
     * Get next required action for investors
     */
    public static function getInvestorNextAction(Milestones $milestone): array
    {
        $currentPhase = self::getCurrentPhase($milestone);

        switch ($currentPhase) {
            case self::PHASE_FUNDING:
                if ($milestone->progress_percentage >= 100 && $milestone->is_funded) {
                    return [
                        'action' => 'request_pre_release',
                        'message' => 'Milestone is fully funded. You can request pre-release documentation.',
                        'urgent' => false
                    ];
                }
                return [
                    'action' => 'monitor_progress',
                    'message' => 'Monitor milestone funding progress',
                    'urgent' => false
                ];

            case self::PHASE_RMEP_VOTING:
                if ($milestone->status === self::STATUS_RMEP_SUBMITTED) {
                    return [
                        'action' => 'vote_on_rmep',
                        'message' => 'Review and vote on the submitted RMEP',
                        'urgent' => true
                    ];
                }
                return [
                    'action' => 'wait_for_rmep',
                    'message' => 'Waiting for business owner to submit RMEP',
                    'urgent' => false
                ];

            case self::PHASE_PRE_RELEASE:
                if ($milestone->status === self::STATUS_PRE_RELEASE_REQUESTED) {
                    return [
                        'action' => 'wait_for_docs',
                        'message' => 'Waiting for business owner to upload pre-release documents',
                        'urgent' => false
                    ];
                }
                return [
                    'action' => 'review_pre_release',
                    'message' => 'Review pre-release documents and vote to approve/reject',
                    'urgent' => true
                ];

            case self::PHASE_MID_MILESTONE:
                return [
                    'action' => 'monitor_execution',
                    'message' => 'Monitor milestone execution progress',
                    'urgent' => false
                ];

            case self::PHASE_FINAL_APPROVAL:
                if ($milestone->status === self::STATUS_EXECUTION_SUBMITTED) {
                    return [
                        'action' => 'review_final_proof',
                        'message' => 'Review final execution proof and vote for approval',
                        'urgent' => true
                    ];
                }
                return [
                    'action' => 'wait_for_final_submission',
                    'message' => 'Waiting for business owner to submit final proof',
                    'urgent' => false
                ];

            case self::PHASE_NON_COMPLIANT:
                return [
                    'action' => 'vote_on_compliance',
                    'message' => 'Vote on non-compliance resolution (Continue/Freeze/Dispute)',
                    'urgent' => true
                ];

            default:
                return [
                    'action' => 'no_action',
                    'message' => 'No action required',
                    'urgent' => false
                ];
        }
    }

    /**
     * Get phase progress information
     */
    public static function getPhaseProgress(Milestones $milestone): array
    {
        $currentPhase = self::getCurrentPhase($milestone);

        switch ($currentPhase) {
            case self::PHASE_FUNDING:
                return [
                    'phase' => 'Funding',
                    'progress' => $milestone->progress_percentage,
                    'status' => $milestone->progress_percentage >= 100 ? 'complete' : 'in_progress'
                ];

            case self::PHASE_RMEP_VOTING:
                $totalRmepVotes = $milestone->rmep_approved + $milestone->rmep_rejected;
                $expectedVoters = $milestone->pending_investors()->count() ?: 1;
                return [
                    'phase' => 'RMEP Voting',
                    'progress' => round(($totalRmepVotes / $expectedVoters) * 100),
                    'status' => $milestone->continuation_approved ? 'complete' : 'in_progress'
                ];

            case self::PHASE_PRE_RELEASE:
                $totalPrVotes = $milestone->pr_approved + $milestone->pr_rejected + $milestone->pr_audit;
                $expectedInvestors = $milestone->investors()->count() ?: 1;
                return [
                    'phase' => 'Pre-Release',
                    'progress' => round(($totalPrVotes / $expectedInvestors) * 100),
                    'status' => $milestone->fund_released_75 ? 'complete' : 'in_progress'
                ];

            case self::PHASE_MID_MILESTONE:
                return [
                    'phase' => 'Mid-Milestone Verification',
                    'progress' => $milestone->mid_milestone_started ? 100 : 50,
                    'status' => $milestone->final_approval_started ? 'complete' : 'in_progress'
                ];

            case self::PHASE_FINAL_APPROVAL:
                return [
                    'phase' => 'Final Approval',
                    'progress' => $milestone->status === self::STATUS_DONE ? 100 : 75,
                    'status' => $milestone->status === self::STATUS_DONE ? 'complete' : 'in_progress'
                ];

            case self::PHASE_COMPLETED:
                return [
                    'phase' => 'Completed',
                    'progress' => 100,
                    'status' => 'complete'
                ];

            case self::PHASE_NON_COMPLIANT:
                return [
                    'phase' => 'Non-Compliant',
                    'progress' => 0,
                    'status' => 'error'
                ];

            default:
                return [
                    'phase' => 'Unknown',
                    'progress' => 0,
                    'status' => 'pending'
                ];
        }
    }

    /**
     * Check if user can perform a specific action
     */
    public static function canUserPerformAction(Milestones $milestone, int $userType, string $action): bool
    {
        $currentPhase = self::getCurrentPhase($milestone);
        $isBusinessOwner = $userType === 4;
        $isInvestor = $userType === 1;

        // Business Owner Actions
        if ($isBusinessOwner) {
            switch ($action) {
                case 'submit_rmep':
                    return $currentPhase === self::PHASE_RMEP_VOTING &&
                           $milestone->status === self::STATUS_CONTINUATION_TRIGGERED;
                case 'upload_pre_release_docs':
                    return $currentPhase === self::PHASE_PRE_RELEASE &&
                           $milestone->status === self::STATUS_PRE_RELEASE_REQUESTED;
                case 'submit_final_proof':
                    return $currentPhase === self::PHASE_FINAL_APPROVAL &&
                           $milestone->status === self::STATUS_IN_PROGRESS;
                default:
                    return false;
            }
        }

        // Investor Actions
        if ($isInvestor) {
            switch ($action) {
                case 'request_pre_release':
                    return $currentPhase === self::PHASE_FUNDING &&
                           $milestone->progress_percentage >= 100 && $milestone->is_funded;
                case 'vote_on_rmep':
                    return $currentPhase === self::PHASE_RMEP_VOTING &&
                           $milestone->status === self::STATUS_RMEP_SUBMITTED;
                case 'review_pre_release':
                    return $currentPhase === self::PHASE_PRE_RELEASE;
                case 'review_final_proof':
                    return $currentPhase === self::PHASE_FINAL_APPROVAL &&
                           $milestone->status === self::STATUS_EXECUTION_SUBMITTED;
                default:
                    return false;
            }
        }

        return false;
    }

    /**
     * Get milestone workflow summary for API responses
     */
    public static function getMilestoneWorkflowSummary(Milestones $milestone, int $userType): array
    {
        $currentPhase = self::getCurrentPhase($milestone);
        $phaseProgress = self::getPhaseProgress($milestone);
        $nextAction = $userType === 4
            ? self::getBusinessOwnerNextAction($milestone)
            : self::getInvestorNextAction($milestone);

        return [
            'current_phase' => $currentPhase,
            'phase_progress' => $phaseProgress,
            'next_action' => $nextAction,
            'can_progress' => self::isPhaseComplete($milestone, $currentPhase),
            'workflow_status' => [
                'funding_complete' => self::isPhaseComplete($milestone, self::PHASE_FUNDING),
                'pre_release_complete' => self::isPhaseComplete($milestone, self::PHASE_PRE_RELEASE),
                'mid_milestone_complete' => self::isPhaseComplete($milestone, self::PHASE_MID_MILESTONE),
                'final_approval_complete' => self::isPhaseComplete($milestone, self::PHASE_FINAL_APPROVAL),
            ]
        ];
    }
}
