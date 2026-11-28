<?php

namespace App\Service\Business;

use App\Models\Business\Listing;
use App\Models\Milestones\Milestones;
use App\Support\CollectionList;
use App\Support\ListShapes;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The Milestones table: one row per business of the owner with its milestone progress (how many are done, the current
 * one, how much of the goal the plan covers). The rows are worked out from two queries (businesses, milestones), so
 * they are searched, filtered, sorted and paged with CollectionList; an owner has few businesses.
 *
 * current_status: the status of the first milestone (by start date) that is not done, "done" when all are, "" with none.
 * plan: none (no milestones) | full (milestones cover the goal) | partial.
 * Filters (all in `filter_schema`): milestone, plan, business (active|inactive), industry, stage, progress / coverage (%),
 * goal / planned / count (min~max), hasMilestones / planComplete / overdue (yes|no), due / created (from~to).
 */
class MilestoneOverviewList
{
    private const DONE = 'done';
    private const NOT_STARTED = ['locked', 'to_do'];

    public function __construct(private int $userId)
    {
    }

    public function respond(Request $request): JsonResponse
    {
        $rows = $this->rows();

        $today = now()->toDateString();
        $list = CollectionList::for($rows, $request)
            ->search(fn ($r) => implode(' ', [$r['name'], $r['category'], $r['current']['title'] ?? '']))
            ->filter('id', fn ($r) => $r['id'])
            // Described filters: the Milestones panel, chips and URL state come from `filter_schema`
            ->options('milestone', fn ($r) => $r['current_status'], label: 'entrepreneur:milestonesList.currentStatus', fallback: 'Milestone status')
            ->options('plan', fn ($r) => $r['plan'], label: 'entrepreneur:milestonesList.planStatus', values: ['full', 'partial', 'none'], fallback: 'Plan')
            ->options('business', fn ($r) => $r['is_active'] ? 'active' : 'inactive', label: 'entrepreneur:milestonesList.businessStatus',
                values: ['active', 'inactive'], optionLabels: ['active' => 'app:active', 'inactive' => 'app:inactive'], fallback: 'Business status')
            ->options('industry', fn ($r) => $r['category'], label: 'app:industry')
            ->options('stage', fn ($r) => $r['stage'], label: 'app:stage')
            ->numberRange('progress', fn ($r) => $r['done_pct'], label: 'app:entFilterDonePct', fallback: 'Milestones done (%)')
            ->numberRange('coverage', fn ($r) => $r['plan_pct'], label: 'app:entFilterPlanCoverage', fallback: 'Goal covered by the plan (%)')
            ->numberRange('goal', fn ($r) => $r['goal'], label: 'app:goal')
            ->numberRange('planned', fn ($r) => $r['planned'], label: 'entrepreneur:milestonesList.exportPlanned', fallback: 'Planned')
            ->numberRange('count', fn ($r) => $r['total'], label: 'app:entFilterMilestoneCount', fallback: 'Number of milestones')
            ->boolean('hasMilestones', fn ($r) => $r['total'] > 0, label: 'app:entFilterHasMilestones', fallback: 'Has milestones')
            ->boolean('planComplete', fn ($r) => $r['plan'] === 'full', label: 'app:entFilterPlanComplete', fallback: 'Plan covers the goal')
            ->boolean('overdue', fn ($r) => $this->isOverdue($r, $today), label: 'app:overdue')
            ->dateRange('due', fn ($r) => (string) $r['due'], label: 'entrepreneur:milestonesList.exportDueDate', presets: ListShapes::DUE_PRESETS, fallback: 'Due date')
            ->dateRange('created', fn ($r) => (string) $r['created_at'], label: 'app:created')
            ->sortable([
                'name' => fn ($r) => mb_strtolower((string) $r['name']),
                'progress' => fn ($r) => $r['done_pct'],
                'plan' => fn ($r) => $r['plan_pct'],
                'created' => fn ($r) => (string) $r['created_at'],
            ], default: '-created');

        [$items, $page] = $list->fetch();

        $stats = [
            'total' => $rows->count(),
            'active' => $rows->where('is_active', true)->count(),
            'with_milestones' => $rows->where('total', '>', 0)->count(),
            'fully_planned' => $rows->where('plan', 'full')->count(),
            'milestones' => $rows->sum('total'),
            'done' => $rows->sum('done'),
        ];

        $data = ListShapes::envelope($items, $page, $list->facets(['milestone', 'plan', 'business', 'industry']), $stats, $list->wantsExport())
            + ['filter_schema' => $list->filterSchema()];

        return response()->json(['business' => $data['items'], 'data' => $data]);
    }

    /** Every business of the owner with its summary; milestones are read in one query for all of them. */
    private function rows(): Collection
    {
        $listings = Listing::where('user_id', $this->userId)->latest()->get();
        $milestones = Milestones::where('user_id', $this->userId)
            ->get(['id', 'listing_id', 'title', 'status', 'amount', 'start_date', 'deadline_date', 'expected_end_date'])
            ->groupBy('listing_id');

        return $listings->map(fn (Listing $l) => $this->summarize($l, $milestones->get($l->id, collect())));
    }

    private function summarize(Listing $business, Collection $own): array
    {
        $own = $own->sort(fn ($a, $b) => ($this->time($a->start_date) <=> $this->time($b->start_date)) ?: $a->id <=> $b->id)->values();
        $status = fn ($m) => mb_strtolower((string) $m->status);

        $done = $own->filter(fn ($m) => $status($m) === self::DONE)->count();
        $inProgress = $own->filter(fn ($m) => $status($m) !== self::DONE && ! in_array($status($m), self::NOT_STARTED, true))->count();
        $current = $own->first(fn ($m) => $status($m) !== self::DONE);
        $goal = (float) $business->investment_needed;
        $planned = (float) $own->sum(fn ($m) => (float) $m->amount);
        $due = $current ? ($current->deadline_date ?: $current->expected_end_date) : null;

        return [
            ...$business->toArray(),
            'is_active' => (int) $business->active === 1,
            'total' => $own->count(),
            'done' => $done,
            'in_progress' => $inProgress,
            'done_pct' => $own->count() ? round($done / $own->count() * 100, 2) : 0,
            'current' => $current ? ['id' => $current->id, 'title' => $current->title, 'status' => $current->status] : null,
            'current_status' => $current ? $status($current) : ($own->count() ? self::DONE : ''),
            'due' => $due ? (string) $due : null,
            'goal' => $goal,
            'planned' => $planned,
            'plan_pct' => $goal > 0 ? round($planned / $goal * 100, 2) : 0,
            'left' => max($goal - $planned, 0),
            'plan' => ! $own->count() ? 'none' : ($goal > 0 && $planned >= $goal ? 'full' : 'partial'),
        ];
    }

    /** The current (first not done) milestone's due day has passed. */
    private function isOverdue(array $row, string $today): bool
    {
        $due = substr((string) $row['due'], 0, 10);

        return $row['current'] !== null && $due !== '' && $due < $today;
    }

    private function time(mixed $date): int
    {
        if (! $date) {
            return 0;
        }
        try {
            return Carbon::parse($date)->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }
}
