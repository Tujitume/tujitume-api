<?php

namespace App\Service\Program;

use App\Models\Organizations\Organization;
use App\Models\Programs\Program;
use App\Models\ProgramIndustry;
use App\Models\Shared\Like;
use App\Models\Shared\Watchlist;
use App\Support\ApiContract;
use App\Support\ListQuery;
use App\Support\ListShapes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /programs/public-programs as a server list (Discover Programs): only programs listed for applicants
 * (Program::listedForApplicants: published or open, and visible; drafts never leave the server), with search, filters,
 * sort, page and export on the database. Each row carries what the old list sent plus `availability` and
 * `industry_name`.
 *
 * availability: open (taking applications now) | closingSoon (open, closes within SOON_DAYS) | upcoming (the first round is
 * published but opens later) | closed (anything else); picking "open" also keeps the closing-soon ones.
 * Filters (all in `filter_schema`): availability, industry, organization, type (single|multi), funding (per applicant) /
 * total (min~max), matchScore / kyc / liked / saved / applied / hasCover / hasVideo (yes|no), deadline / opens (from~to);
 * id for deep links. Sorts: newest, deadline, amount, funding, title, applied (most applications).
 */
class ProgramDiscoverList
{
    public const SOON_DAYS = 7;
    public const AVAILABILITY = ['open', 'closingSoon', 'upcoming', 'closed'];

    private ?array $availability = null;

    public function __construct(private ?int $userId)
    {
    }

    public function respond(Request $request): JsonResponse
    {
        $base = Program::listedForApplicants()
            ->with(['owner', 'industry:id,name', 'rounds:id,program_id,round_number,status,open_date,close_date,opens_at,closes_at'])
            ->withCount(['liked', 'applications']);
        $mine = fn (string $relation) => fn (Builder $q, bool $yes) => $yes
            ? $q->whereHas($relation, fn (Builder $r) => $this->ownedByMe($r, $relation))
            : $q->whereDoesntHave($relation, fn (Builder $r) => $this->ownedByMe($r, $relation));
        $filled = fn (string $column) => fn (Builder $q, bool $yes) => $yes
            ? $q->whereNotNull("programs.$column")->where("programs.$column", '!=', '')
            : $q->where(fn (Builder $n) => $n->whereNull("programs.$column")->orWhere("programs.$column", ''));

        $list = ListQuery::for($base, $request)
            ->search(['program_title', 'program_description', 'industry.name', 'organization.name', 'organization.display_name', 'owner.display_name'])
            ->filter('id', 'id')
            ->options('availability', function (Builder $q, array $values) {
                $wanted = in_array('open', $values, true) ? [...$values, 'closingSoon'] : $values;
                $q->whereIn('programs.id', array_keys(array_intersect($this->availability(), $wanted)));
            }, label: 'entrepreneur:capitalList.availability', values: self::AVAILABILITY, fallback: 'Availability',
                optionLabels: ['open' => 'app:entFilterOpenNow', 'closingSoon' => 'app:closingSoon', 'upcoming' => 'app:entFilterOpeningSoon', 'closed' => 'app:closed'],
                countBy: fn (Builder $q) => $this->availabilityCounts($q))
            ->entity('industry', 'program_industry_id', ProgramIndustry::class, 'name', label: 'app:industry')
            ->entity('organization', 'organization_id', fn (array $ids) => Organization::whereIn('id', $ids)->get(['id', 'name', 'display_name'])
                ->mapWithKeys(fn (Organization $o) => [$o->id => $o->display_name ?: $o->name])->all(), label: 'app:organization')
            ->options('type', DB::raw("CASE WHEN programs.program_type = 'multi_round' THEN 'multi' ELSE 'single' END"), label: 'app:entFilterProgramType',
                values: ['single', 'multi'], optionLabels: ['single' => 'app:singleRound', 'multi' => 'app:multiRound'], fallback: 'Program type')
            ->numberRange('funding', 'funding_per_business', label: 'app:entFilterFundingPerApplicant', fallback: 'Funding per applicant')
            ->numberRange('total', 'total_program_amount', label: 'app:entFilterTotalProgramAmount', fallback: 'Total program amount')
            ->boolean('matchScore', 'use_match_score', label: 'app:entFilterUsesMatchScore', fallback: 'Uses a match score')
            ->boolean('kyc', 'require_kyc_verification_to_apply', label: 'app:entFilterKycRequired', fallback: 'KYC required to apply')
            ->dateRange('deadline', 'application_deadline', label: 'app:entFilterApplicationDeadline', presets: ListShapes::DUE_PRESETS, fallback: 'Application deadline')
            ->dateRange('opens', 'application_start_date', label: 'app:entFilterOpensOn', presets: ListShapes::DUE_PRESETS, fallback: 'Opens on')
            ->boolean('liked', $mine('liked'), label: 'app:entFilterLikedByMe', fallback: 'Liked by me')
            ->boolean('saved', $mine('watchlists'), label: 'app:entFilterSavedByMe', fallback: 'Saved by me')
            ->boolean('applied', $mine('applications'), label: 'app:entFilterAppliedByMe', fallback: 'Applied by me')
            ->boolean('hasCover', $filled('cover_image'), label: 'app:entFilterHasCoverImage', fallback: 'Has a cover image')
            ->boolean('hasVideo', $filled('intro_video_url'), label: 'app:entFilterHasIntroVideo', fallback: 'Has an intro video')
            ->sortable([
                'newest' => 'created_at',
                // the exact closing moment, else the deadline's day; programs without one sort last
                'deadline' => fn (Builder $q, string $dir) => $q
                    ->orderByRaw('CASE WHEN COALESCE(programs.application_closes_at, programs.application_deadline) IS NULL THEN 1 ELSE 0 END')
                    ->orderByRaw('COALESCE(programs.application_closes_at, programs.application_deadline) '.$dir),
                'amount' => 'total_program_amount',
                'funding' => 'funding_per_business',
                'title' => 'program_title',
                'applied' => fn (Builder $q, string $dir) => $q->orderBy('applications_count', $dir),
            ], default: '-newest');

        [$rows, $page] = $list->fetch();
        $this->decorate($rows);

        $all = $this->availability();
        $stats = [
            'total' => count($all),
            'open' => count(array_intersect($all, ['open', 'closingSoon'])),
            'closing_soon' => count(array_intersect($all, ['closingSoon'])),
            'upcoming' => count(array_intersect($all, ['upcoming'])),
        ];
        $data = ListShapes::envelope($rows, $page, [], $stats, $list->wantsExport()) + ['filter_schema' => $list->filterSchema()];

        // `programs` keeps the old top-level key for anything reading it
        return response()->json(['success' => true, 'message' => 'Programs fetched successfully.', 'data' => $data, 'programs' => $data['items']]);
    }

    /** The same per-row fields the old list sent (one query per kind, not per row), plus availability. */
    private function decorate($rows): void
    {
        Program::annotateApplicationState($rows);
        $ids = $rows->pluck('id')->all();
        $liked = $this->userId && $ids
            ? Like::where('type', 'program')->where('user_id', $this->userId)->whereIn('listing_id', $ids)->pluck('listing_id')->map(fn ($id) => (int) $id)->all()
            : [];
        $availability = $this->availability();

        foreach ($rows as $program) {
            $program->pitch_count = $program->applications_count ?? 0;
            $program->liked = in_array((int) $program->id, $liked, true);
            $program->owner_website = $program->owner->website ?? null;
            $program->organization_name = $program->owner?->display_name;
            $program->industry_name = $program->industry?->name;
            $program->availability = $availability[$program->id] ?? 'closed';
            $program->status = ApiContract::statusMeta('program', $program->status);
        }
    }

    /** $relation rows of the signed-in person (no one signed in: none). */
    private function ownedByMe(Builder $q, string $relation): void
    {
        $q->where($q->getModel()->qualifyColumn('user_id'), $this->userId ?? 0);
        if ($relation === 'applications') {
            $q->where('program_applications.status', '!=', 'draft'); // a draft is not an application yet
        }
    }

    /** [{value, count}] of availability over the programs a query leaves ("open" counts the closing-soon ones too). */
    private function availabilityCounts(Builder $q): array
    {
        $ids = $q->setEagerLoads([])->toBase()->select('programs.id')->pluck('id')->all();
        $states = array_intersect_key($this->availability(), array_flip($ids));
        $counts = array_count_values($states);
        $counts['open'] = ($counts['open'] ?? 0) + ($counts['closingSoon'] ?? 0);

        return array_map(fn ($value) => ['value' => $value, 'count' => $counts[$value] ?? 0], self::AVAILABILITY);
    }

    /** id => availability of every listed program, worked out once per request (it depends on the first round). */
    private function availability(): array
    {
        return $this->availability ??= Program::listedForApplicants()
            ->with('rounds:id,program_id,round_number,status,open_date,close_date,opens_at,closes_at')
            ->get()
            ->mapWithKeys(fn (Program $p) => [$p->id => self::availabilityOf($p)])
            ->all();
    }

    public static function availabilityOf(Program $program): string
    {
        $reason = $program->applicationsClosedReason();
        if ($reason === Program::NOT_OPEN_YET) {
            return 'upcoming';
        }
        if ($reason !== null) {
            return 'closed';
        }
        $closes = $program->applicationRound()?->closesAt() ?? $program->closesAt();

        return $closes && $closes->lte(now()->addDays(self::SOON_DAYS)) ? 'closingSoon' : 'open';
    }
}
