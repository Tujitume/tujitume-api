<?php

namespace App\Service\Program;

use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Support\ListQuery;
use App\Support\ListShapes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /programs/my-applications/awarded (the Program Deal Room list) for a business owner: their awarded applications with
 * search, filters, sort, page and export on the database, and the cards' totals over all of them.
 *
 * The old keys stay: `applications` is every row (no page asked, or export=1) or Laravel's page object (page / per_page);
 * the list envelope is in `data` (items, pagination, facets, stats).
 * setup: not_started (also when never set) | in_progress | completed.
 * Filters (all in `filter_schema`): program (id, or title for older links), setup, sector, location, awarded / requested /
 * match (min~max), needsAction / escrow / hasMilestones (yes|no), applied (from~to); id for deep links.
 */
class AwardedApplicationList
{
    public const SETUP = ['not_started', 'in_progress', 'completed'];
    /** Funding setup states where the program owner waits for the applicant (a revised plan, a requested change). */
    private const WAITING_ON_APPLICANT = ['awaiting_applicant_revision', 'revision_requested'];
    private const SETUP_SQL = "CASE WHEN program_applications.funding_setup_status IN ('in_progress', 'completed') THEN program_applications.funding_setup_status ELSE 'not_started' END";

    public function __construct(private int $userId)
    {
    }

    public function respond(Request $request): JsonResponse
    {
        $base = $this->base();
        ListShapes::extraSort($base, $request, [
            'program' => fn (Builder $q, string $dir) => $q->orderBy(Program::select('program_title')->whereColumn('programs.id', 'program_applications.program_id')->limit(1), $dir),
        ]);
        $programs = Program::whereIn('id', $this->base()->distinct()->pluck('program_id'))->pluck('program_title', 'id');

        $place = fn ($a) => ListShapes::place($a->headquarters_location);
        $list = ListQuery::for($base, $request)
            ->search(['startup_name', 'sector', 'headquarters_location', 'program.program_title'])
            ->filter('id', 'id')
            // Described filters: the Program Deal Room panel, chips and URL state come from `filter_schema`
            // program: ids from the panel; a program title (older links) still works
            ->options('program', ListShapes::idOrName('program_id', 'program', 'program_title'), label: 'app:program',
                optionLabels: fn (array $ids) => $programs->only($ids)->all(), countBy: 'program_id', searchable: true)
            ->options('setup', DB::raw(self::SETUP_SQL), label: 'app:fundingSetup', values: self::SETUP,
                optionLabels: ['not_started' => 'app:notStarted', 'in_progress' => 'app:inProgress', 'completed' => 'app:completed'])
            // a placeholder sector ("Not specified") is not offered
            ->options('sector', 'sector', label: 'app:sector', countBy: ListShapes::derivedCount(['sector'], fn ($a) => ListShapes::clean($a->sector)))
            ->options('location', ListShapes::derivedFilter($this->base(), ['headquarters_location'], $place), label: 'app:location',
                countBy: ListShapes::derivedCount(['headquarters_location'], $place))
            ->numberRange('awarded', 'awarded_amount', label: 'app:entFilterAwardedAmount', fallback: 'Amount awarded')
            ->numberRange('requested', 'total_amount_requested', label: 'app:amountRequested2')
            ->numberRange('match', 'match_score', label: 'app:matchScore')
            ->boolean('needsAction', fn (Builder $q, bool $yes) => $yes
                ? $q->whereIn('program_applications.funding_setup_status', self::WAITING_ON_APPLICANT)
                : $q->where(fn (Builder $n) => $n->whereNull('program_applications.funding_setup_status')->orWhereNotIn('program_applications.funding_setup_status', self::WAITING_ON_APPLICANT)),
                label: 'app:entFilterWaitingOnYou', fallback: 'Waiting on you')
            ->boolean('escrow', 'escrow_funded', label: 'app:entFilterEscrowFunded', fallback: 'Funds in escrow')
            ->boolean('hasMilestones', fn (Builder $q, bool $yes) => $yes ? $q->whereHas('program_milestones') : $q->whereDoesntHave('program_milestones'),
                label: 'app:entFilterHasMilestones', fallback: 'Has milestones')
            ->dateRange('applied', 'created_at', label: 'app:appliedOn')
            ->sortable(['id' => 'id', 'business' => 'startup_name', 'awarded' => 'awarded_amount', 'requested' => 'total_amount_requested'], default: '-id');

        [$rows, $page] = $list->fetch();
        $items = $rows->map(fn (ProgramApplication $pitch) => [
            ...$pitch->toArray(),
            'status' => \App\Support\ApiContract::statusMeta('program_application', $pitch->status),
        ])->values();

        // `facets` keeps the old values (programs by title) for older screens; the panel reads filter_schema
        $facets = $list->facets(['sector', 'setup', 'location']);
        $facets['program'] = ListShapes::derivedFacet($list->query('program', sorted: false), ['program_id'], fn ($a) => $programs->get($a->program_id));

        $data = ListShapes::envelope($items, $page, $facets, $this->stats(), $list->wantsExport()) + ['filter_schema' => $list->filterSchema()];

        if ($page) {
            $page->setCollection($items);

            return response()->json(['applications' => $page, 'data' => $data]);
        }

        return response()->json(['applications' => $items, 'export_truncated' => false, 'data' => $data]);
    }

    private function base(): Builder
    {
        return ProgramApplication::query()
            ->with(['program:id,program_title,currency,program_type,user_id,organization_id']) // owner + organization: the logo on each row
            ->where('program_applications.user_id', $this->userId)
            ->whereIn('program_applications.status', ['awarded', 'in_monitoring', 'completed']);
    }

    private function setupOf(?string $value): string
    {
        return in_array($value, self::SETUP, true) ? $value : 'not_started';
    }

    /** Totals for the three cards, over every awarded application. */
    private function stats(): array
    {
        $all = $this->base()->get(['id', 'program_id', 'startup_name', 'awarded_amount', 'total_amount_requested', 'funding_setup_status']);
        $setup = $all->countBy(fn ($a) => $this->setupOf($a->funding_setup_status));

        return [
            'total' => $all->count(),
            'programs' => $all->pluck('program_id')->filter()->unique()->count(),
            'businesses' => $all->pluck('startup_name')->filter()->unique()->count(),
            'awarded_total' => round((float) $all->sum('awarded_amount'), 2),
            'requested_total' => round((float) $all->sum('total_amount_requested'), 2),
            'currencies' => $all->map(fn ($a) => $a->program?->currency)->filter()->unique()->values()->all(),
            'setup' => collect(self::SETUP)->mapWithKeys(fn ($s) => [$s => $setup->get($s, 0)])->all(),
        ];
    }
}
