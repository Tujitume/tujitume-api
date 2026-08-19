<?php

namespace App\Service\Program;

use App\Models\Programs\Disbursement;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\Monitoring\MECheckpoint;
use App\Models\Programs\Monitoring\MESubmission;
use App\Models\Programs\Monitoring\MESubmissionFile;

class ProgramMonitoringAnalyticsService
{
    public function getMeOverview(ProgramApplication $app): array
    {
        $milestones = $app->program_milestones()->get();
        $checkpoints = MECheckpoint::where('app_id', $app->id)
            ->with(['submission.files', 'siteVisit'])
            ->get();
        $submissions = MESubmission::where('app_id', $app->id)->get();
        $siteVisits = $checkpoints->pluck('siteVisit')->filter();
        $disbursements = Disbursement::whereIn('milestone_id', $milestones->pluck('id'))->get();

        $totalCheckpoints = $checkpoints->count();
        $submittedCheckpoints = $checkpoints->whereIn('status', ['submitted', 'verified'])->count();
        $verifiedCheckpoints = $checkpoints->where('status', 'verified')->count();

        $totalMilestones = $milestones->count();
        $completedMilestones = $milestones->where('status', 'completed')->count();
        $releasedFunds = $disbursements->where('status', 'completed')->sum('amount');
        $totalFunds = $app->awarded_amount ?? 0;

        $topStats = [
            'submission_completion' => $totalCheckpoints > 0
                ? round(($submittedCheckpoints / $totalCheckpoints) * 100, 1) : 0,
            'success_completion' => $totalCheckpoints > 0
                ? round(($verifiedCheckpoints / $totalCheckpoints) * 100, 1) : 0,
            'average_it_progress' => $totalMilestones > 0
                ? round(($completedMilestones / $totalMilestones) * 100, 1) : 0,
            'funding_coverage' => $totalFunds > 0
                ? round(($releasedFunds / $totalFunds) * 100, 1) : 0,
            'approved_advances' => $app->status === 'awarded' ? 1 : 0,
            'follow_adjustments' => $submissions->where('status', 'changes_requested')->count(),
        ];

        $flaggedItems = [];

        $missingEvidence = $submissions->filter(function ($sub) {
            return $sub->status === 'submitted' && $sub->files()->count() === 0;
        })->count();

        if ($missingEvidence > 0) {
            $flaggedItems[] = [
                'type' => 'error',
                'title' => 'Missing evidence',
                'description' => "Complete evidence not submitted for {$missingEvidence} budget usage report(s)",
            ];
        }

        $budgetVariance = $milestones->filter(
            fn($m) => $m->amount > 0 && $m->status !== 'completed'
        )->count();

        if ($budgetVariance > 0) {
            $flaggedItems[] = [
                'type' => 'review',
                'title' => 'Budget variance',
                'description' => "Procurement spend above budget, review applicant budget classification for {$budgetVariance} milestone(s)",
            ];
        }

        $overdueCheckpoints = $checkpoints->filter(
            fn($c) => $c->due_date && $c->due_date < now() && $c->status === 'pending'
        )->count();

        if ($overdueCheckpoints > 0) {
            $flaggedItems[] = [
                'type' => 'action',
                'title' => 'Overdue checkpoints',
                'description' => "{$overdueCheckpoints} checkpoint(s) past due date with no submission",
            ];
        }

        $upcomingDeadlines = $checkpoints->filter(
            fn($c) => $c->due_date && $c->due_date->diffInDays(now()) <= 7 && $c->status === 'pending'
        )->count();

        if ($upcomingDeadlines > 0) {
            $flaggedItems[] = [
                'type' => 'action',
                'title' => 'Deadline recommendation',
                'description' => "{$upcomingDeadlines} checkpoint deadline(s) approaching within 7 days",
            ];
        }

        $evaluationSummary = [
            'qualifying_criteria' => $checkpoints->whereIn('type', ['monitoring', 'reporting'])->count(),
            'on_hand_districts' => $siteVisits->where('status', 'completed')->count(),
            'past_comments' => $submissions->whereNotNull('reviewer_note')->count(),
            'awardees' => 1,
            'approved_applicants' => $app->status === 'awarded' ? 1 : 0,
        ];

        $latestDocuments = MESubmissionFile::whereHas('submission', function ($q) use ($app) {
            $q->where('app_id', $app->id);
        })
            ->latest()
            ->take(10)
            ->get(['id', 'file_path', 'original_filename', 'file_type', 'created_at']);

        return [
            'top_stats' => $topStats,
            'flagged_items' => $flaggedItems,
            'evaluation_summary' => $evaluationSummary,
            'latest_documents' => $latestDocuments,
        ];
    }

    public function getApplicationImpact(ProgramApplication $app): array
    {
        $milestones = $app->program_milestones()->get();
        $checkpoints = MECheckpoint::where('app_id', $app->id)
            ->with(['submission.files', 'siteVisit'])
            ->get();
        $submissions = MESubmission::where('app_id', $app->id)->get();
        $disbursements = Disbursement::whereIn('milestone_id', $milestones->pluck('id'))->get();

        $totalCheckpoints = $checkpoints->count();
        $verifiedCheckpoints = $checkpoints->where('status', 'verified')->count();
        $totalEvidence = MESubmissionFile::whereHas('submission', function ($q) use ($app) {
            $q->where('app_id', $app->id);
        })->count();

        $summaryStats = [
            'programee_portfolio' => 1,
            'stakeholder_efficacy' => $totalEvidence,
            'impact_target_achievement' => $totalCheckpoints > 0
                ? round(($verifiedCheckpoints / $totalCheckpoints) * 100, 1) : 0,
            'evidence_submitted' => $totalEvidence,
        ];

        $totalMilestones = $milestones->count();
        $completedMilestones = $milestones->where('status', 'completed')->count();

        $operationalCompletion = [
            'percentage' => $totalMilestones > 0
                ? round(($completedMilestones / $totalMilestones) * 100, 1) : 0,
            'completed' => $completedMilestones,
            'total' => $totalMilestones,
        ];

        $successMetrics = $checkpoints->sortBy('due_date')->map(fn($checkpoint) => [
            'date' => $checkpoint->due_date?->format('M d, Y'),
            'checkpoint' => $checkpoint->checkpoint_name,
            'cumulative_progress' => $checkpoint->status === 'verified' ? 100 : ($checkpoint->status === 'submitted' ? 50 : 0),
            'problems_kpis' => count($checkpoint->kpis_to_track ?? []),
            'review_early' => $checkpoint->submission?->reviewed_at
                ? ($checkpoint->submission->reviewed_at < $checkpoint->due_date ? 1 : 0) : 0,
        ])->values();

        $operationalSummary = [
            'submitted' => $submissions->where('status', 'submitted')->count(),
            'approved' => $submissions->where('status', 'verified')->count(),
            'rejected' => $submissions->where('status', 'changes_requested')->count(),
            'not_selected' => $checkpoints->where('status', 'pending')->count(),
            'programed' => $disbursements->where('status', 'completed')->count(),
        ];

        $kpiAchievement = $checkpoints->flatMap(function ($checkpoint) {
            $actualValues = $checkpoint->submission?->kpi_actual_values ?? [];
            $targets = $checkpoint->kpis_to_track ?? [];

            return collect($targets)->map(function ($kpi) use ($actualValues) {
                $actual = collect($actualValues)->firstWhere('kpi', $kpi);
                return [
                    'kpi' => $kpi,
                    'target' => 100,
                    'actual' => $actual ? (float) $actual['value'] : 0,
                ];
            });
        })->groupBy('kpi')->map(fn($group) => [
            'kpi' => $group->first()['kpi'],
            'target' => 100,
            'actual' => round($group->avg('actual'), 1),
        ])->values();

        $milestoneCoverage = [
            'monitoring' => $checkpoints->where('type', 'monitoring')->count(),
            'reporting' => $checkpoints->where('type', 'reporting')->count(),
            'approved' => $checkpoints->where('status', 'verified')->count(),
        ];

        $evidenceCoverage = MESubmissionFile::whereHas('submission', function ($q) use ($app) {
            $q->where('app_id', $app->id);
        })
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($row) => [
                'date' => $row->date,
                'count' => $row->count,
            ]);

        $checkpointReport = $checkpoints->sortBy('display_order')->map(fn($checkpoint) => [
            'id' => $checkpoint->id,
            'checkpoint_name' => $checkpoint->checkpoint_name,
            'type' => $checkpoint->type,
            'status' => [
                'value' => $checkpoint->status,
                'color' => config('status.me_checkpoint.' . $checkpoint->status, 'gray'),
            ],
            'due_date' => $checkpoint->due_date?->format('M d, Y'),
            'submitted_at' => $checkpoint->submission?->submitted_at?->format('M d, Y'),
            'reviewer_note' => $checkpoint->submission?->reviewer_note,
            'evidence_count' => $checkpoint->submission?->files->count() ?? 0,
            'site_visit' => $checkpoint->siteVisit ? [
                'status' => $checkpoint->siteVisit->status,
                'visit_date' => $checkpoint->siteVisit->start_date?->format('M d, Y'),
            ] : null,
        ])->values();

        $portfolioSummary = [
            'total_awarded' => $app->awarded_amount,
            'released_to_date' => $disbursements->where('status', 'completed')->sum('amount'),
            'milestones_total' => $totalMilestones,
            'milestones_done' => $completedMilestones,
        ];

        $impactSummary = [
            'jobs_created' => $this->extractKpiValue($submissions, 'Jobs created'),
            'revenue_generated' => $this->extractKpiValue($submissions, 'Revenue generated'),
            'score_completion' => $verifiedCheckpoints,
            'score_quantity' => $totalCheckpoints,
            'financial_achieved' => $disbursements->where('status', 'completed')->sum('amount'),
            'amount_type' => $app->awarded_amount,
        ];

        return [
            'summary_stats' => $summaryStats,
            'operational_completion' => $operationalCompletion,
            'success_metrics' => $successMetrics,
            'operational_summary' => $operationalSummary,
            'kpi_achievement' => $kpiAchievement,
            'milestone_coverage' => $milestoneCoverage,
            'evidence_coverage' => $evidenceCoverage,
            'checkpoint_report' => $checkpointReport,
            'portfolio_summary' => $portfolioSummary,
            'impact_summary' => $impactSummary,
        ];
    }

    private function extractKpiValue($submissions, string $kpiName): mixed
    {
        foreach ($submissions as $submission) {
            $kpis = $submission->kpi_actual_values ?? [];
            $found = collect($kpis)->firstWhere('kpi', $kpiName);
            if ($found) {
                return $found['value'];
            }
        }

        return 0;
    }
}
