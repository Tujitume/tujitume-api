<?php

namespace App\Service\Services;

use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Organizations\Organization;
use App\Models\Services\ServiceBooking;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How well each live service suits the person looking (Explore Services puts the best first).
 *
 * A score from 0 to 100, from what the app knows about them:
 *   - what they showed interest in: services they liked, booked or spent time on (the details panel), counted by
 *     category. In an organisation, what their teammates looked at counts too, at half weight      (up to 40)
 *   - what someone in their position usually needs: a program organisation runs programmes (Business Coaches,
 *     project management, monitoring, finance); an entrepreneur builds a business; an investor checks one (up to 25)
 *   - their sectors: the organisation's focus sectors, or the entrepreneur's businesses' categories,
 *     against the sectors the service serves                                                               (up to 15)
 *   - how well rated the service is, and by how many people                                                (up to 15)
 *   - nearby: the service is in the person's city                                                          (up to 5)
 * Each service also gets the reasons behind its score (keys the page words).
 */
class ServiceMatcher
{
    /** What someone of this account type usually looks for (category names as the add-service form saves them). */
    private const NEEDS = [
        4 => ['Business Coach' => 1.0, 'Project Management' => 0.9, 'Finance, Accounting & Tax' => 0.7, 'Business Planning' => 0.6, 'Legal' => 0.4],
        1 => ['Business Planning' => 1.0, 'Finance, Accounting & Tax' => 0.9, 'Marketing' => 0.8, 'Branding and Design' => 0.7, 'Legal' => 0.7, 'IT' => 0.6, 'Business Coach' => 0.6],
        2 => ['Legal' => 1.0, 'Finance, Accounting & Tax' => 1.0, 'Business Planning' => 0.7, 'Project Management' => 0.6],
        5 => ['Legal' => 1.0, 'Finance, Accounting & Tax' => 1.0, 'Business Planning' => 0.7, 'Project Management' => 0.6],
        3 => ['Marketing' => 0.8, 'Branding and Design' => 0.7, 'IT' => 0.6, 'Legal' => 0.5],
    ];

    /**
     * Adds match_score (0–100) and match_reasons to each service.
     *
     * @param  Collection<int, \App\Models\Services\Services>  $services
     */
    public static function score(Collection $services, ?User $user): Collection
    {
        if (! $user) {
            return $services->each(function ($s) {
                $s->match_score = self::qualityPoints($s);
                $s->match_reasons = self::qualityPoints($s) >= 12 ? ['top_rated'] : [];
            });
        }

        [$mine, $team] = self::interestByCategory($user);
        $needs = self::NEEDS[(int) $user->user_type_id] ?? [];
        $sectors = self::sectorsOf($user);
        $city = mb_strtolower(trim((string) $user->city));

        return $services->each(function ($s) use ($mine, $team, $needs, $sectors, $city) {
            $reasons = [];
            $category = (string) $s->category;

            // Interest saturates: a like and a few minutes count a lot, more of the same adds less and less
            $own = (float) ($mine[$category] ?? 0);
            $shared = (float) ($team[$category] ?? 0);
            $interestPoints = 40 * (1 - exp(-($own + $shared) / 4));
            if ($own >= 1) $reasons[] = 'your_interest';
            elseif ($shared >= 1) $reasons[] = 'team_interest';

            $needPoints = 25 * ($needs[$category] ?? 0);
            if ($needPoints >= 15) $reasons[] = 'role_need';

            $served = collect((array) ($s->business_sector_focus ?? []))->map(fn ($x) => mb_strtolower(trim((string) $x)))->filter();
            $overlap = $sectors->isEmpty() ? 0 : $served->intersect($sectors)->count();
            $sectorPoints = $overlap ? min(15, 8 + 4 * ($overlap - 1)) : 0;
            if ($sectorPoints) $reasons[] = 'your_sector';

            $quality = self::qualityPoints($s);
            if ($quality >= 12) $reasons[] = 'top_rated';

            $near = $city !== '' && str_contains(mb_strtolower((string) $s->location), $city) ? 5 : 0;
            if ($near) $reasons[] = 'nearby';

            $s->match_score = (int) round(min(100, $interestPoints + $needPoints + $sectorPoints + $quality + $near));
            $s->match_reasons = $reasons;
        });
    }

    /** Best match first, then the best rated, then the newest. */
    public static function sort(Collection $services): Collection
    {
        return $services->sort(fn ($a, $b) => [$b->match_score, (float) $b->rating, $b->id] <=> [$a->match_score, (float) $a->rating, $a->id])->values();
    }

    /** Up to 15 points: the rating out of 5, trusted more as more people rate it. */
    private static function qualityPoints($service): float
    {
        $rating = max(0.0, min(5.0, (float) $service->rating));
        $trust = min(1.0, log(1 + (int) $service->rating_count) / log(51)); // ~50 ratings is fully trusted

        return round(15 * ($rating / 5) * (0.4 + 0.6 * $trust), 1);
    }

    /**
     * Interest per service category: likes count 3, bookings 4, each minute in the details panel 1 (at most 5 per
     * service). Teammates in the same organisation count half.
     *
     * @return array{0: Collection<string, float>, 1: Collection<string, float>} the person's own, and their team's
     */
    private static function interestByCategory(User $user): array
    {
        $mine = self::signals([$user->id]);

        $teammates = ((int) $user->user_type_id === 4 && $user->organization_id)
            ? User::where('organization_id', $user->organization_id)->where('id', '!=', $user->id)->limit(200)->pluck('id')->all()
            : [];
        $team = $teammates ? self::signals($teammates)->map(fn ($v) => $v / 2) : collect();

        return [$mine, $team];
    }

    /** @return Collection<string, float> category => weight, for these people */
    private static function signals(array $userIds): Collection
    {
        $weights = collect();
        $add = function (iterable $rows, callable $weigh) use ($weights) {
            foreach ($rows as $row) {
                if (! $row->category) continue;
                $weights[$row->category] = ($weights[$row->category] ?? 0) + $weigh($row);
            }
        };

        $add(DB::table('likes')->join('services', 'services.id', '=', 'likes.listing_id')
            ->where('likes.type', 'service')->whereIn('likes.user_id', $userIds)->select('services.category')->get(), fn () => 3);

        $add(ServiceBooking::query()->join('services', 'services.id', '=', 'service_bookings.service_id')
            ->whereIn('service_bookings.booker_id', $userIds)->select('services.category')->get(), fn () => 4);

        $add(DB::table('service_views')->join('services', 'services.id', '=', 'service_views.service_id')
            ->whereIn('service_views.user_id', $userIds)->select('services.category', 'service_views.seconds')->get(),
            fn ($row) => min(5, $row->seconds / 60));

        return $weights;
    }

    /** The sectors the person works in (lower case): the organisation's focus sectors, or their businesses' categories. */
    private static function sectorsOf(User $user): Collection
    {
        $sectors = collect();
        if ($user->organization_id) {
            $org = Organization::select('id', 'focus_sectors', 'sector')->find($user->organization_id);
            $sectors = $sectors->merge((array) ($org?->focus_sectors ?? []))->push($org?->sector);
        }
        if ((int) $user->user_type_id === 1) {
            $sectors = $sectors->merge(Listing::where('user_id', $user->id)->pluck('category'));
        }

        return $sectors->filter()->map(fn ($x) => mb_strtolower(trim((string) $x)))->filter()->unique()->values();
    }
}
