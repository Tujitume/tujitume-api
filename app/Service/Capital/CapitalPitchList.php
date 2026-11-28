<?php

namespace App\Service\Capital;

use App\Models\Auth\User;
use App\Models\Capital\CapitalWatchlist;
use App\Models\Capital\StartupPitches;
use App\Support\CollectionList;
use App\Support\ListShapes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /capital/my_pitches as a server list (page, per_page, search, filters, sort, export): a capital provider's
 * applications over all their offers (the team sees the owner's), or an entrepreneur's own.
 *
 * Row: the application (as my_pitches sends it, without milestones) plus offer_title, application_status
 * (pending | accepted | rejected), match_band, in_my_watchlist, applied_at.
 * Filters: status, offer (capital_id), match (score band 90-100 | 80-89 | 70-79 | 60-69 | <60), sector,
 * shortlist (yes|no: in the owner's watchlist), applied (from~to), requested (min~max); id for deep links.
 * Sort: applied (default -applied), score, requested, name.
 * Stats (over every application, not the page): total, pending, accepted, rejected, avg_match_score, accept_rate,
 * shortlisted.
 */
class CapitalPitchList
{
    public const BANDS = ['90-100', '80-89', '70-79', '60-69', '<60'];

    public function __construct(private User $user)
    {
    }

    public static function band(mixed $score): string
    {
        $s = (float) $score;

        return $s >= 90 ? '90-100' : ($s >= 80 ? '80-89' : ($s >= 70 ? '70-79' : ($s >= 60 ? '60-69' : '<60')));
    }

    public static function status(mixed $status): string
    {
        return match ((int) $status) {
            StartupPitches::STATUS_ACCEPTED => 'accepted',
            StartupPitches::STATUS_REJECTED => 'rejected',
            default => 'pending',
        };
    }

    public function respond(Request $request): JsonResponse
    {
        $all = $this->rows();
        $offers = $all->pluck('offer_title', 'capital_id')->mapWithKeys(fn ($t, $id) => [(string) $id => (string) $t])->all();

        $list = CollectionList::for($all, $request)
            ->search(fn ($r) => implode(' ', [$r['startup_name'], $r['contact_person_name'], $r['contact_person_email'], $r['sector'], $r['headquarters_location'], $r['offer_title']]))
            ->filter('id', fn ($r) => $r['id'])
            ->options('status', fn ($r) => $r['application_status'], label: 'app:status', values: ['pending', 'accepted', 'rejected'],
                optionLabels: ['pending' => 'app:pending2', 'accepted' => 'app:accepted', 'rejected' => 'app:rejected'], fallback: 'Status')
            ->options('offer', fn ($r) => (string) $r['capital_id'], label: 'app:capitalOffer',
                optionLabels: fn (array $ids) => array_intersect_key($offers, array_flip($ids)), fallback: 'Capital offer')
            ->options('match', fn ($r) => $r['match_band'], label: 'app:matchScore', values: self::BANDS, fallback: 'Match score')
            ->options('sector', fn ($r) => (string) $r['sector'], label: 'app:sector', fallback: 'Sector')
            ->boolean('shortlist', fn ($r) => $r['in_my_watchlist'], label: 'app:shortlisted', fallback: 'Shortlisted')
            ->dateRange('applied', fn ($r) => (string) $r['applied_at'], label: 'app:appliedOn', fallback: 'Applied on')
            ->numberRange('requested', fn ($r) => $r['total_amount_requested'], label: 'app:amountRequested2', fallback: 'Amount requested')
            ->sortable([
                'applied' => fn ($r) => (string) $r['applied_at'],
                'score' => fn ($r) => (float) $r['score'],
                'requested' => fn ($r) => (float) $r['total_amount_requested'],
                'name' => fn ($r) => mb_strtolower((string) $r['startup_name']),
            ], default: '-applied');

        [$items, $page] = $list->fetch();
        $data = ListShapes::envelope($items, $page, $list->facets(['status', 'offer', 'match', 'sector']), $this->stats($all), $list->wantsExport())
            + ['filter_schema' => $list->filterSchema()];

        // `pitches` keeps the old top-level key
        return response()->json(['pitches' => $data['items'], 'data' => $data]);
    }

    private function rows(): Collection
    {
        if (CapitalAccess::isCapital($this->user)) {
            $ownerId = CapitalAccess::ownerId($this->user);
            $query = StartupPitches::where('capital_owner_id', $ownerId);
            $watch = CapitalWatchlist::where('capital_owner_id', $ownerId)->pluck('pitch_id')->map(fn ($id) => (int) $id)->all();
        } else {
            $query = StartupPitches::whereIn('user_id', array_unique([$this->user->id, $this->user->organizationOwnerId()]));
            $watch = [];
        }

        return $query->with(['capital_offer:id,offer_title,user_id', 'latestAgreement'])->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(function (StartupPitches $p) use ($watch) {
                $offerTitle = $p->capital_offer?->offer_title;

                return [
                    ...$p->toArray(),
                    'offer_title' => $offerTitle,
                    'application_status' => self::status($p->status),
                    'match_band' => self::band($p->score),
                    'in_my_watchlist' => in_array((int) $p->id, $watch, true),
                    'applied_at' => $p->created_at?->toIso8601String(),
                ];
            })->values();
    }

    private function stats(Collection $rows): array
    {
        $total = $rows->count();
        $accepted = $rows->where('application_status', 'accepted')->count();

        return [
            'total' => $total,
            'pending' => $rows->where('application_status', 'pending')->count(),
            'accepted' => $accepted,
            'rejected' => $rows->where('application_status', 'rejected')->count(),
            'avg_match_score' => $total ? round((float) $rows->avg(fn ($r) => (float) $r['score']), 2) : 0,
            'accept_rate' => $total ? round($accepted / $total * 100, 2) : 0,
            'shortlisted' => $rows->where('in_my_watchlist', true)->count(),
        ];
    }
}
