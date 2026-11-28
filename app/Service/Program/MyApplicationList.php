<?php

namespace App\Service\Program;

use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramWatchlist;
use App\Models\Programs\Rounds\ProgramRound;
use App\Support\ListQuery;
use App\Support\ListShapes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * /programs/my-applications as a server list (My Program Applications): the same people see the same applications as the
 * full list (an organization's team: its programs' submitted applications; anyone else: their own, drafts included),
 * with search, filters, sort, page and export on the database, and the summary cards over the whole set.
 *
 * status filter groups: draft | pending | approved | rejected | other (as the page shows them).
 * Filters (all in `filter_schema`; `facets` keeps status / type / program-by-title for the older page): status, type
 * (single|multi), program (id, or title), round, requested / awarded / match (min~max), scored (yes|no), applied (from~to).
 */
class MyApplicationList
{
    public const GROUPS = [
        'draft' => ['draft'],
        'pending' => ['pending', 'submitted', 'under_review', 'in_review'],
        'approved' => ['approved', 'awarded', 'in_monitoring', 'completed'],
        'rejected' => ['rejected'],
    ];

    /** $user comes from ProgramApplicationController::get_role() (role + program_owner_id set). */
    public function __construct(private $user)
    {
    }

    public static function groupOf(?string $status): string
    {
        $status = mb_strtolower((string) $status);
        foreach (self::GROUPS as $group => $values) {
            if (in_array($status, $values, true)) {
                return $group;
            }
        }

        return 'other';
    }

    public function respond(Request $request): JsonResponse
    {
        $base = $this->base();
        ListShapes::extraSort($base, $request, [
            'title' => fn (Builder $q, string $dir) => $q->orderBy(Program::select('program_title')->whereColumn('programs.id', 'program_applications.program_id')->limit(1), $dir),
        ]);
        $programs = $this->programs();

        $kindOf = fn ($p) => ($programs->get($p->program_id)?->program_type === 'multi_round') ? 'multi' : 'single';
        $list = ListQuery::for($base, $request)
            ->search(['startup_name', 'sector', 'program.program_title'])
            ->filter('id', 'id')
            // Described filters (`filter_schema`); the older page keeps building its panel from `facets`
            ->options('status', DB::raw(self::groupSql()), label: 'app:status', values: [...array_keys(self::GROUPS), 'other'],
                optionLabels: ['draft' => 'app:draft', 'pending' => 'app:pending2', 'approved' => 'app:approved', 'rejected' => 'app:rejected', 'other' => 'app:other'])
            ->options('type', function (Builder $q, array $values) {
                $multi = in_array('multi', $values, true);
                $single = in_array('single', $values, true);
                if ($multi !== $single) {
                    $multi
                        ? $q->whereHas('program', fn (Builder $p) => $p->where('program_type', 'multi_round'))
                        : $q->whereDoesntHave('program', fn (Builder $p) => $p->where('program_type', 'multi_round'));
                }
            }, label: 'app:type', values: ['single', 'multi'], optionLabels: ['single' => 'app:singleRound', 'multi' => 'app:multiRound'],
                countBy: ListShapes::derivedCount(['program_id'], $kindOf))
            // program: ids from the panel; a program title (the older page, older links) still works
            ->options('program', ListShapes::idOrName('program_id', 'program', 'program_title'), label: 'app:program',
                optionLabels: fn (array $ids) => $programs->pluck('program_title', 'id')->only($ids)->all(), countBy: 'program_id', searchable: true)
            ->entity('round', 'current_round_id', fn (array $ids) => self::roundNames($ids), label: 'app:round2')
            ->numberRange('requested', 'total_amount_requested', label: 'app:amountRequested2')
            ->numberRange('awarded', 'awarded_amount', label: 'app:entFilterAwardedAmount', fallback: 'Amount awarded')
            ->numberRange('match', 'match_score', label: 'app:matchScore')
            ->boolean('scored', fn (Builder $q, bool $yes) => $yes ? $q->whereNotNull('program_applications.match_score') : $q->whereNull('program_applications.match_score'),
                label: 'app:entFilterHasMatchScore', fallback: 'Has a match score')
            ->dateRange('applied', 'created_at', label: 'app:appliedOn')
            ->sortable(['applied' => 'created_at', 'requested' => 'total_amount_requested', 'match' => 'match_score'], default: '-applied');

        [$rows, $page] = $list->fetch();

        $watchlist = $this->watchlistIds();
        $items = $rows->map(function (ProgramApplication $pitch) use ($watchlist) {
            $pitch->in_my_watchlist = in_array($pitch->id, $watchlist);
            $status = $pitch->status;
            $pitch->status = \App\Support\ApiContract::statusMeta('program_application', $status);

            return $pitch;
        });

        $facets = [
            'status' => ListShapes::derivedFacet($list->query('status', sorted: false), ['status'], fn ($p) => self::groupOf($p->status)),
            'type' => ListShapes::derivedFacet($list->query('type', sorted: false), ['program_id'], $kindOf),
            'program' => ListShapes::derivedFacet($list->query('program', sorted: false), ['program_id'], fn ($p) => $programs->get($p->program_id)?->program_title),
        ];

        $data = ListShapes::envelope($items, $page, $facets, $this->stats($programs), $list->wantsExport()) + ['filter_schema' => $list->filterSchema()];

        return response()->json(['pitches' => $data['items'], 'data' => $data]);
    }

    /** The status group of a row in SQL (see GROUPS); anything else is "other". */
    private static function groupSql(): string
    {
        $cases = collect(self::GROUPS)->map(fn ($statuses, $group) => "WHEN LOWER(program_applications.status) IN ('".implode("', '", $statuses)."') THEN '$group'")->implode(' ');

        return "CASE $cases ELSE 'other' END";
    }

    /** "Program · Round" for each round id (round names repeat across programs). */
    private static function roundNames(array $ids): array
    {
        return ProgramRound::with('program:id,program_title')->whereIn('id', $ids)->get(['id', 'program_id', 'round_number', 'round_name'])
            ->mapWithKeys(fn (ProgramRound $r) => [$r->id => trim(($r->program?->program_title ? $r->program->program_title.' · ' : '').($r->round_name ?: 'Round '.$r->round_number))])
            ->all();
    }

    private function isTeam(): bool
    {
        return in_array($this->user->role, ['editor', 'viewer', 'admin', 'super_admin']) || (int) $this->user->user_type_id === 4;
    }

    private function ownerId(): int
    {
        return (int) (in_array($this->user->role, ['editor', 'viewer', 'admin', 'super_admin']) ? $this->user->program_owner_id : $this->user->id);
    }

    /** The same applications the full list returns for this person. */
    private function base(): Builder
    {
        $query = ProgramApplication::query()->with(['program', 'currentRound', 'program_milestones']);

        return $this->isTeam()
            // ISSUE-38: applications to any program of the organization, whoever on the team created it
            ? $query->whereIn('program_applications.program_id', \App\Models\Programs\Program::ofOrganization($this->user)->select('id'))->whereNot('program_applications.status', 'draft')
            : $query->where('program_applications.user_id', $this->user->id);
    }

    private function watchlistIds(): array
    {
        return $this->isTeam() ? ProgramWatchlist::where('program_owner_id', $this->ownerId())->pluck('pitch_id')->all() : [];
    }

    /** The programs these applications are for (titles, types and currencies for the counts), in one query. */
    private function programs(): Collection
    {
        $ids = $this->base()->setEagerLoads([])->distinct()->pluck('program_id');

        return Program::whereIn('id', $ids)->get(['id', 'program_title', 'program_type', 'currency'])->keyBy('id');
    }

    /** The summary the old list sent (drafts are not counted), over every application, whatever the search. */
    private function stats(Collection $programs): array
    {
        $all = $this->base()->setEagerLoads([])->get(['id', 'status', 'match_score', 'total_amount_requested', 'awarded_amount', 'program_id']);
        $submitted = $all->filter(fn ($p) => mb_strtolower((string) $p->status) !== 'draft');
        $groups = $all->countBy(fn ($p) => self::groupOf($p->status));
        $scores = $submitted->pluck('match_score')->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v) => (float) $v);
        $accepted = $groups->get('approved', 0);
        $rejected = $groups->get('rejected', 0);

        return [
            'total' => $submitted->count(),
            'pending' => $groups->get('pending', 0),
            'accepted' => $accepted,
            'rejected' => $rejected,
            'draft' => $groups->get('draft', 0),
            'scored' => $scores->count(),
            'avg_match' => $scores->count() ? round($scores->avg(), 2) : 0,
            'best_match' => $scores->count() ? round($scores->max(), 2) : null,
            'accept_rate' => $accepted + $rejected ? round($accepted / ($accepted + $rejected) * 100, 2) : 0,
            'total_requested' => round((float) $submitted->sum('total_amount_requested'), 2),
            'total_awarded' => round((float) $submitted->sum('awarded_amount'), 2),
            // one currency → the totals can be shown in it; several → they are mixed and shown without a symbol
            'currencies' => $submitted->map(fn ($p) => $programs->get($p->program_id)?->currency)->filter()->unique()->values()->all(),
        ];
    }
}
