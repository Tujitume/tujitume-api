<?php

namespace App\Models\Programs;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\ProgramIndustry;
use Illuminate\Database\Eloquent\Model;

/** An import started on Programs → Imports: the choices made so far, the step reached and the file added. */
class ProgramImport extends Model
{
    /** Whether the import can still be changed: not while it runs, nor once every program was created. */
    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'partial'], true);
    }

    public const MODES = ['new', 'existing', 'many', 'form'];  // form: one program set up from a Google Form's questions;  // many: several programs in one file, each row with its own stage
    public const MIXED_STAGE = 'mixed';                  // the stage of a many-programs import
    public const STAGES = ['setup', 'review', 'finalization', 'funding', 'disbursement', 'monitoring'];
    public const STEPS = ['program', 'upload', 'review', 'check', 'import'];
    // importing: step 5 is running; partial: it finished but some programs could not be created (they can be tried again)
    public const STATUSES = ['draft', 'importing', 'completed', 'partial'];

    protected $guarded = [];

    protected $hidden = ['file_path', 'plan'];

    protected $casts = ['file_size' => 'integer', 'data' => 'array', 'plan' => 'array', 'progress' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function industry()
    {
        return $this->belongsTo(ProgramIndustry::class, 'program_industry_id');
    }

    /** What the app shows: the import, who started it, the program's title and whether a file is attached. */
    public function toView(bool $withData = true): array
    {
        return [
            'id' => $this->id,
            'mode' => $this->mode,
            'program_id' => $this->program_id,
            'program_title' => $this->program?->program_title,
            'program_industry_id' => $this->program_industry_id,
            'stage' => $this->stage,
            'current_stage' => $this->current_stage,
            'step' => $this->step,
            'status' => $this->status,
            'file' => $this->file_path ? ['name' => $this->file_name, 'size' => $this->file_size] : null,
            'data' => $withData ? $this->data : null,
            'progress' => $this->progressView(),
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'started_by' => $this->user ? trim($this->user->first_name.' '.$this->user->last_name) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** Every application the import created, across its programs (in program order). */
    public function applicationIds(): array
    {
        return collect($this->progress['results'] ?? [])->where('status', 'created')
            ->flatMap(fn ($r) => $r['application_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** The imported applications whose applicant has not been told yet. */
    // Someone not emailed because their address cannot receive mail (skipped_ids) is not offered again
    public function applicationsToNotify(): array
    {
        return array_values(array_diff($this->applicationIds(), array_map('intval', $this->progress['notify']['sent_ids'] ?? []), array_map('intval', $this->progress['notify']['skipped_ids'] ?? [])));
    }

    /** People (one per email) among the given applications. */
    public static function peopleIn(array $applicationIds): int
    {
        if (! $applicationIds) return 0;

        return ProgramApplication::whereIn('id', $applicationIds)->with('user:id,email')->get(['id', 'user_id', 'email'])
            ->map(fn ($a) => mb_strtolower((string) ($a->user?->email ?: $a->email)))->filter()->unique()->count();
    }

    /**
     * Start at review and later: the applications the import made that a round set to AI review (AI-assisted or
     * AI-only) can review now, and that have not been handed to the AI yet. Imports never start AI review on their
     * own; the person chooses to after the import.
     */
    public function applicationsForAi(): array
    {
        $ids = $this->applicationIds();
        if (! $ids) return [];

        return ProgramApplication::whereIn('id', $ids)
            ->whereIn('round_status', ProgramApplication::AI_REVIEWABLE_STATUSES)
            ->whereHas('currentRound', fn ($q) => $q->whereIn('review_method', ['ai_assisted', 'ai_only'])->where('status', '!=', 'finalized'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from((new \App\Models\Programs\Ai\ApplicationAiReview)->getTable().' as r')->whereColumn('r.application_id', 'program_applications.id'))
            ->pluck('id')->all();
    }

    /** Start at disbursement: the milestone suppliers the import made (paid direct to a supplier) not told yet. */
    public function supplierLinksToTell(): array
    {
        $all = collect($this->progress['results'] ?? [])->where('status', 'created')->flatMap(fn ($r) => $r['supplier_link_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();

        return array_values(array_diff($all, array_map('intval', $this->progress['suppliers']['sent_ids'] ?? []), array_map('intval', $this->progress['suppliers']['skipped_ids'] ?? [])));
    }

    /** Suppliers (one per supplier, however many milestones) among the given milestone suppliers. */
    public static function suppliersAmong(array $linkIds): int
    {
        return $linkIds ? \App\Models\Programs\MilestoneSupplier::whereIn('id', $linkIds)->distinct()->count('supplier_id') : 0;
    }

    /** Start at monitoring & evaluation: the scheduled site visits the import made whose officer has not been told yet. */
    public function siteVisitsToTell(): array
    {
        $all = collect($this->progress['results'] ?? [])->where('status', 'created')->flatMap(fn ($r) => $r['site_visit_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();

        return array_values(array_diff($all, array_map('intval', $this->progress['inspectors']['sent_ids'] ?? []), array_map('intval', $this->progress['inspectors']['skipped_ids'] ?? [])));
    }

    /** Officers (one per person, however many visits) among the given site visits. */
    public static function officersAmong(array $visitIds): int
    {
        return $visitIds ? \App\Models\Programs\Monitoring\MESiteVisit::whereIn('id', $visitIds)->distinct()->count('reviewer_id') : 0;
    }

    /** The suppliers the import added to the owner's list (a welcome is due to them). */
    public function newSupplierIds(): array
    {
        return collect($this->progress['results'] ?? [])->flatMap(fn ($r) => $r['new_supplier_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** The Business Coach places the import made that have not been invited yet. */
    public function reviewersToInvite(): array
    {
        $all = collect($this->progress['results'] ?? [])->where('status', 'created')->flatMap(fn ($r) => $r['reviewer_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();

        return array_values(array_diff($all, array_map('intval', $this->progress['invite']['sent_ids'] ?? []), array_map('intval', $this->progress['invite']['skipped_ids'] ?? [])));
    }

    /** People among the given Business Coach places (one person can review several rounds). */
    public static function peopleAmongReviewers(array $reviewerIds): int
    {
        return $reviewerIds ? \App\Models\Programs\Rounds\RoundReviewer::whereIn('id', $reviewerIds)->distinct()->count('user_id') : 0;
    }

    /** The progress as the page needs it: counts, not the lists of ids kept for the work. */
    private function progressView(): ?array
    {
        $progress = $this->progress;
        if (! $progress) return $progress;
        $progress['results'] = collect($progress['results'] ?? [])->map(fn ($r) => array_diff_key($r, ['application_ids' => true, 'reviewer_ids' => true, 'supplier_link_ids' => true, 'new_supplier_ids' => true]))->all();
        $invite = $progress['invite'] ?? null;
        if ($invite) unset($invite['sent_ids'], $invite['skipped_ids']);
        $invite['to_tell'] = in_array($this->status, ['completed', 'partial'], true) ? self::peopleAmongReviewers($this->reviewersToInvite()) : 0;
        $progress['invite'] = $invite;
        $notify = $progress['notify'] ?? null;
        if ($notify) unset($notify['sent_ids'], $notify['skipped_ids']);
        $notify['to_tell'] = in_array($this->status, ['completed', 'partial'], true) ? self::peopleIn($this->applicationsToNotify()) : 0;
        $progress['notify'] = $notify;
        $ai = $progress['ai'] ?? null;
        $ai['to_tell'] = in_array($this->status, ['completed', 'partial'], true) ? count($this->applicationsForAi()) : 0;
        $progress['ai'] = $ai;
        $suppliers = $progress['suppliers'] ?? null;
        if ($suppliers) unset($suppliers['sent_ids'], $suppliers['skipped_ids']);
        $suppliers['to_tell'] = in_array($this->status, ['completed', 'partial'], true) ? self::suppliersAmong($this->supplierLinksToTell()) : 0;
        $progress['suppliers'] = $suppliers;
        $inspectors = $progress['inspectors'] ?? null;
        if ($inspectors) unset($inspectors['sent_ids'], $inspectors['skipped_ids']);
        $inspectors['to_tell'] = in_array($this->status, ['completed', 'partial'], true) ? self::officersAmong($this->siteVisitsToTell()) : 0;
        $progress['inspectors'] = $inspectors;

        return $progress;
    }
}
