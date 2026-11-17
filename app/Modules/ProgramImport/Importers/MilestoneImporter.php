<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Pipeline\ImportRowException;

class MilestoneImporter extends Importer
{
    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        $app = ProgramApplication::findOrFail($c->findOrFail('application', $v['application_ref']));
        if ($app->status !== 'awarded') {
            throw new ImportRowException('Milestones can only be added to awarded applications.');
        }
        if ($id = $c->find('milestone', $row->external_ref, $app->program_id)) {
            return ['milestone', $id];
        }
        if (ProgramMilestone::where('app_id', $app->id)->where('sequence_order', $v['sequence_order'])->exists()) {
            throw new ImportRowException("This application already has a milestone with sequence {$v['sequence_order']}.");
        }
        $land = $c->landing->milestone((int) $v['sequence_order']);
        $isTemplate = $land['is_template'] ?? false;
        $status = $isTemplate ? 'pending' : ($v['status'] ?? $land['status'] ?? 'pending');
        $done = $status === 'completion_approved';
        // A completed milestone was paid: later milestones can only be released once earlier ones are 'released' (canReleaseInSequence).
        $release = $isTemplate ? 'locked' : ($v['fund_release_status'] ?? ($done ? 'released' : ($land['fund_release_status'] ?? 'locked')));
        $released = $release === 'released';
        if ($release === 'approved' && ! empty($v['mprv_required'])) {
            $release = 'locked';
        }
        $m = ProgramMilestone::create([
            'app_id' => $app->id, 'sequence_order' => $v['sequence_order'], 'title' => mb_substr($v['title'], 0, 500),
            'description' => $v['description'] ?? null, 'amount' => $v['amount'], 'status' => $status, 'is_template' => $isTemplate,
            'created_by_role' => 'program_owner', 'fund_release_status' => $release, 'fund_released' => $released,
            'estimated_completion_date' => $v['due_date'] ?? null,
            'mprv_required' => (bool) ($v['mprv_required'] ?? false), 'mid_milestone_required' => (bool) ($v['mid_milestone_required'] ?? false), 'final_approval_required' => (bool) ($v['final_approval_required'] ?? false),
            // Pre-agreements are bypassed for milestones that are already active (Q-AGREEMENTS): a required verification starts as agreed
            'mprv_ready' => ! $isTemplate && ! empty($v['mprv_required']), 'mid_milestone_ready' => ! $isTemplate && ! empty($v['mid_milestone_required']), 'final_approval_ready' => ! $isTemplate && ! empty($v['final_approval_required']),
            'completed_at' => $done ? ($v['completed_at'] ?? now()) : null, 'completion_approved_at' => $done ? ($v['completed_at'] ?? now()) : null,
        ]);
        $c->record($m, 'created', [], ['status' => $status, 'fund_release_status' => $release, 'is_template' => $isTemplate]);
        $c->link('milestone', $m, $row->external_ref, $app->program_id);
        $c->touchedApplications[$app->id] = true;

        return ['milestone', $m->id];
    }

    /** Mirror the real flow after activation: only the next unfinished milestone is approved, later ones stay locked. */
    public function finish(CommitContext $c): void
    {
        foreach (array_keys($c->touchedApplications) as $appId) {
            $ms = ProgramMilestone::where('app_id', $appId)->orderBy('sequence_order')->get();
            if ($ms->isEmpty()) {
                continue;
            }
            $app = ProgramApplication::find($appId);
            if ($ms->every(fn ($m) => ! $m->is_template)) {
                $done = fn ($m) => $m->status === 'completion_approved' || $m->fund_release_status === 'released';
                $next = $ms->first(fn ($m) => ! $done($m));
                foreach ($ms as $m) {
                    if ($done($m)) {
                        continue;
                    }
                    if ($m->id === $next->id && $m->fund_release_status === 'locked' && ! $m->mprv_required) {
                        $m->forceFill(['fund_release_status' => 'approved'])->save();
                    } elseif ($m->id !== $next->id && $m->fund_release_status === 'approved') {
                        $m->forceFill(['fund_release_status' => 'locked'])->save();
                    }
                }
            }
            if ($app && ! $app->awarded_amount) {
                $app->forceFill(['awarded_amount' => $ms->sum('amount')])->save();
            }
        }
        $c->allMilestonesDone = ProgramMilestone::whereIn('app_id', array_keys($c->touchedApplications))->exists()
            && ! ProgramMilestone::whereIn('app_id', array_keys($c->touchedApplications))->where('status', '!=', 'completion_approved')->exists();
    }
}
