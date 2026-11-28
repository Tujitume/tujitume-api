<?php

namespace App\Service\Program;

use App\Models\Auth\User;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Support\ListQuery;
use App\Support\ListShapes;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET programs/owner/awarded-applications: the owner's Program Deal Room list. Every awarded application across the
 * programs the signed-in account may run (ProgramAccess::owns(): the programs they created and, for a program-team member
 * of an organization, the organization owner's programs; the same programs GET programs/{id}/applications/awarded answers for), with
 * search, filters, sort, page and export on the server, the Filter popover's counts and the cards' totals over all of them.
 *
 * Rows keep the shape of the per-program endpoint (the application, its program and milestones, status as {value, color})
 * plus the values the table shows: program_title, kind (single|multi), setup, place and the milestone counts.
 * Filters (all in `filter_schema`): program, setup, type, sector, location, awarded / requested amount and milestone count
 * (ranges), milestones to review, has milestones, M&E program, has checkpoints, KYC verified (yes/no), applied (dates).
 */
class OwnerAwardedList
{
    /** funding_setup_status values, in the order the filter lists them; anything else reads as not_started */
    public const SETUP = ['not_started', 'in_progress', 'revision_requested', 'awaiting_applicant_revision', 'completed'];

    private const MILESTONE_DONE = ['completed', 'completion_approved'];
    private const MILESTONE_TO_REVIEW = ['submitted', 'under_review'];

    /** @var list<int>|null */
    private ?array $programIds = null;

    public function __construct(private User $user)
    {
    }

    public function respond(Request $request): JsonResponse
    {
        $base = $this->base()
            ->with(['program:id,program_title,currency,program_type,user_id,organization_id', 'program_milestones:id,app_id,status,sequence_order,title'])
            ->withCount('program_milestones');

        ListShapes::extraSort($base, $request, [
            'program' => fn (Builder $q, string $dir) => $q->orderBy(Program::select('program_title')->whereColumn('programs.id', 'program_applications.program_id')->limit(1), $dir),
            'milestones' => fn (Builder $q, string $dir) => $q->orderBy('program_milestones_count', $dir),
        ]);

        $types = fn () => Program::whereIn('id', $this->programIds())->pluck('program_type', 'id');
        $milestones = fn (array $statuses) => fn ($m) => $m->select(DB::raw(1))->from('program_milestones')
            ->whereColumn('program_milestones.app_id', 'program_applications.id')
            ->when($statuses, fn ($w) => $w->whereIn(DB::raw('LOWER(program_milestones.status)'), $statuses));
        $exists = fn (Closure $sub) => fn (Builder $q, bool $yes) => $yes ? $q->whereExists($sub) : $q->whereNotExists($sub);

        // Described filters: the Deal Room panel, chips and URL state come from `filter_schema` (old keys and values unchanged)
        $list = ListQuery::for($base, $request)
            ->search(['startup_name', 'contact_person_name', 'contact_person_email', 'sector', 'headquarters_location', 'program.program_title'])
            ->filter('id', 'id')
            ->entity('program', 'program_id', fn (array $ids) => Program::whereIn('id', $ids)->pluck('program_title', 'id')->all(), label: 'app:program')
            ->options('setup', function (Builder $q, array $values) {
                $q->where(function (Builder $any) use ($values) {
                    $known = array_values(array_intersect($values, array_diff(self::SETUP, ['not_started'])));
                    if ($known) {
                        $any->orWhereIn('program_applications.funding_setup_status', $known);
                    }
                    if (in_array('not_started', $values, true)) {
                        $any->orWhereNull('program_applications.funding_setup_status')
                            ->orWhereNotIn('program_applications.funding_setup_status', array_diff(self::SETUP, ['not_started']));
                    }
                });
            }, label: 'app:fundingSetup', values: self::SETUP,
                countBy: fn (Builder $q) => ListShapes::derivedFacet($q, ['funding_setup_status'], fn ($a) => $this->setupOf($a->funding_setup_status)))
            ->options('type', function (Builder $q, array $values) {
                $multi = in_array('multi', $values, true);
                $single = in_array('single', $values, true);
                if ($multi !== $single) {
                    $q->whereHas('program', fn (Builder $p) => $multi ? $p->where('program_type', 'multi_round') : $p->where(fn ($s) => $s->whereNull('program_type')->orWhere('program_type', '!=', 'multi_round')));
                }
            }, label: 'app:type', values: ['single', 'multi'], optionLabels: ['single' => 'app:singleRound', 'multi' => 'app:multiRound'],
                countBy: fn (Builder $q) => ListShapes::counts($q->setEagerLoads([])->toBase()->pluck('program_applications.program_id')
                    ->map(fn ($id) => ($types()->get($id) === 'multi_round') ? 'multi' : 'single')))
            ->options('sector', ListShapes::derivedFilter($this->base(), ['sector'], fn ($a) => ListShapes::clean($a->sector)), label: 'app:sector',
                countBy: fn (Builder $q) => ListShapes::derivedFacet($q, ['sector'], fn ($a) => ListShapes::clean($a->sector)))
            ->options('location', ListShapes::derivedFilter($this->base(), ['headquarters_location'], fn ($a) => ListShapes::place($a->headquarters_location)), label: 'app:location',
                countBy: fn (Builder $q) => ListShapes::derivedFacet($q, ['headquarters_location'], fn ($a) => ListShapes::place($a->headquarters_location)))
            ->numberRange('awarded', 'awarded_amount', label: 'app:exportAmountAwarded')
            ->numberRange('requested', 'total_amount_requested', label: 'app:amountRequested')
            ->numberRange('milestones', DB::raw('(select count(*) from program_milestones where program_milestones.app_id = program_applications.id)'), label: 'app:milestones')
            ->boolean('to_review', $exists($milestones(self::MILESTONE_TO_REVIEW)), label: 'app:listFilterMilestonesToReview')
            ->boolean('has_milestones', $exists($milestones([])), label: 'app:listFilterHasMilestones')
            // M&E: the awardee's program has a monitoring plan (any checkpoint), and the awardee has checkpoints of their own
            ->boolean('me_program', $exists(fn ($c) => $c->select(DB::raw(1))->from('m_e_checkpoints')
                ->whereColumn('m_e_checkpoints.program_id', 'program_applications.program_id')), label: 'app:listFilterMeProgram')
            ->boolean('has_checkpoints', $exists(fn ($c) => $c->select(DB::raw(1))->from('m_e_checkpoints')
                ->whereColumn('m_e_checkpoints.app_id', 'program_applications.id')), label: 'app:listFilterHasCheckpoints')
            ->boolean('kyc', $exists(fn ($k) => $k->select(DB::raw(1))->from('kyc_verifications')
                ->whereColumn('kyc_verifications.user_id', 'program_applications.user_id')->where('kyc_verifications.status', 'verified')), label: 'app:listFilterKycVerified')
            ->dateRange('applied', 'created_at', label: 'app:appliedOn')
            ->sortable([
                'id' => 'id',
                'business' => 'startup_name',
                'contact' => 'contact_person_name',
                'awarded' => 'awarded_amount',
                'requested' => 'total_amount_requested',
                'setup' => 'funding_setup_status',
            ], default: '-id');

        [$rows, $page] = $list->fetch();

        $items = $rows->map(function (ProgramApplication $a) {
            $statuses = $a->program_milestones->map(fn ($m) => strtolower((string) $m->status));

            return [
                ...$a->toArray(),
                'status' => \App\Support\ApiContract::statusMeta('program_application', $a->status),
                'program_title' => $a->program?->program_title,
                'kind' => $a->program?->program_type === 'multi_round' ? 'multi' : 'single',
                'setup' => $this->setupOf($a->funding_setup_status),
                'place' => ListShapes::place($a->headquarters_location),
                'milestone_count' => $statuses->count(),
                'milestones_done' => $statuses->filter(fn ($s) => in_array($s, self::MILESTONE_DONE, true))->count(),
                'milestones_to_review' => $statuses->filter(fn ($s) => in_array($s, self::MILESTONE_TO_REVIEW, true))->count(),
            ];
        })->values();

        // `facets` stays for older screens; the panel reads filter_schema (the same counts)
        $schema = collect($list->filterSchema())->keyBy('key');
        $facets = collect(['program', 'setup', 'type', 'sector', 'location'])->mapWithKeys(fn ($key) => [$key => collect($schema[$key]['options'] ?? [])
            ->filter(fn ($o) => ($o['count'] ?? 0) > 0)
            ->map(fn ($o) => ['value' => $key === 'program' ? (int) $o['value'] : $o['value'], 'count' => $o['count']] + ($key === 'program' ? ['label' => $o['label'] ?? null] : []))
            ->values()->all()])->all();

        return response()->json([
            'success' => true,
            'message' => 'Awarded applications retrieved',
            'data' => ListShapes::envelope($items, $page, $facets, $this->stats(), $list->wantsExport()) + ['filter_schema' => $schema->values()->all(), 'export_truncated' => false],
        ]);
    }

    /**
     * The programs ProgramAccess::owns() lets this account run (drafts have no awardees): their own, and the organization
     * owner's when they are on its program team. Worked out once per request.
     *
     * @return list<int>
     */
    private function programIds(): array
    {
        if ($this->programIds === null) {
            // ISSUE-38: the organization's programs, whoever on the team created them
            $this->programIds = Program::ofOrganization($this->user)->where('status', '!=', 'draft')->get(['id', 'user_id', 'organization_id'])
                ->filter(fn (Program $p) => ProgramAccess::owns($p))
                ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }

        return $this->programIds;
    }

    private function base(): Builder
    {
        return ProgramApplication::query()
            ->whereIn('program_applications.program_id', $this->programIds() ?: [0])
            ->whereIn('program_applications.status', ['awarded', 'in_monitoring', 'completed']);
    }

    private function setupOf(?string $value): string
    {
        return in_array($value, self::SETUP, true) ? $value : 'not_started';
    }

    /** Totals for the three cards, over every awarded application of the account (not the search). */
    private function stats(): array
    {
        $all = $this->base()->with('program:id,currency')->get(['id', 'program_id', 'awarded_amount', 'total_amount_requested', 'funding_setup_status']);
        $setup = $all->countBy(fn ($a) => $this->setupOf($a->funding_setup_status));
        $milestones = ProgramMilestone::whereIn('app_id', $all->pluck('id'))->pluck('status')->map(fn ($s) => strtolower((string) $s));

        return [
            'total' => $all->count(),
            'programs' => $all->pluck('program_id')->filter()->unique()->count(),
            'awarded_total' => round((float) $all->sum('awarded_amount'), 2),
            'requested_total' => round((float) $all->sum('total_amount_requested'), 2),
            'currencies' => $all->map(fn ($a) => $a->program?->currency)->filter()->unique()->values()->all(),
            'setup' => collect(self::SETUP)->mapWithKeys(fn ($s) => [$s => $setup->get($s, 0)])->all(),
            'milestones' => $milestones->count(),
            'milestones_done' => $milestones->filter(fn ($s) => in_array($s, self::MILESTONE_DONE, true))->count(),
            'milestones_to_review' => $milestones->filter(fn ($s) => in_array($s, self::MILESTONE_TO_REVIEW, true))->count(),
        ];
    }
}
