<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalOffer;
use App\Models\Programs\Program;
use App\Models\Services\Services;
use App\Service\AI\CapitalMarketAnalysis;
use App\Service\AI\SuggestionsForACapital;
use App\Service\AI\SuggestionsForAProgram;
use App\Service\AI\SuggestionsForAListing;
use App\Service\AI\SuggestionsForAService;
use App\Service\AiScore\ListingScore;
use App\Service\AiScore\ListingToServiceScore;
use App\Service\AiScore\ServiceScore;
use App\Service\Misc\ErrorLogService;
use App\Models\Shared\Like;
use App\Support\ApiContract;
use App\Support\CollectionList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AiController extends Controller
{
    public function index(Request $request)
    {
        try{
            $investor = Auth::user();
            $listings = Listing::withCount('liked')->get();
            $listingScore = new ListingScore($investor);
            // which of them this person liked, in one query (not one per business)
            $likedIds = Like::where('user_id', $investor->id)->where('type', 'listing')
                ->whereIn('listing_id', $listings->pluck('id'))->pluck('listing_id')->flip();

            $scores = [];
            foreach ($listings as $listing) {
                $listing->liked = $likedIds->has($listing->id);
                $score = $listingScore->calculate($listing);
                $scores[] = [
                    'score'   => $score['total'],
                    'listing' => $listing,
                    'breakdown'   => $score['breakdown'],
                ];
            }
            // best match first (sorted once, not after every row)
            usort($scores, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $b['listing']->id <=> $a['listing']->id);

            // ?page / per_page / search / filters / sort / export: the recommendations table and cards
            if (ApiContract::wantsPagination($request) || $request->boolean('export')
                || $request->filled('search') || $request->filled('filters') || $request->filled('sort')) {
                return $this->matchList(collect($scores), $request, $listingScore->hasPreferences());
            }

            return response()->json(['scores' => $scores, 'has_preferences' => $listingScore->hasPreferences()]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /** Investment goal bands for the Goal filter: [value, from, to) — the same bands the page labels */
    private const GOAL_BANDS = [['under-1k', 0, 1000], ['1k-10k', 1000, 10000], ['10k-50k', 10000, 50000], ['50k-100k', 50000, 100000], ['over-100k', 100000, INF]];

    private const PLACEHOLDERS = ['not specified', 'not provided', 'not available', 'unspecified', 'n/a', 'na', 'none', 'null', 'undefined', '-', '—'];

    private static function goalBand($listing): ?string
    {
        $goal = (float) ($listing->investment_needed ?? 0);
        foreach (self::GOAL_BANDS as [$value, $from, $to]) {
            if ($goal >= $from && $goal < $to) {
                return $value;
            }
        }

        return null;
    }

    /** A text value, or null when it is empty or a placeholder ("Not specified") */
    private static function known($value): ?string
    {
        $text = trim((string) $value);

        return $text === '' || in_array(strtolower($text), self::PLACEHOLDERS, true) ? null : $text;
    }

    /** "Nairobi, Kenya" from a full address: its last two parts (placeholders like "Not specified" count as none) */
    private static function placeOf($location): string
    {
        $text = trim((string) $location);
        if (in_array(mb_strtolower($text), self::PLACEHOLDERS, true)) {
            return '';
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $text)), fn ($p) => $p !== ''));

        return implode(', ', array_slice($parts, -2));
    }

    private function matchList($scores, Request $request, bool $hasPreferences = true)
    {
        $list = CollectionList::for($scores, $request)
            ->search(fn ($s) => implode(' ', [$s['listing']->name, $s['listing']->details, $s['listing']->category, $s['listing']->location]))
            ->filter('id', fn ($s) => $s['listing']->id)
            // Described filters: the panel, chips and URL state come from `filter_schema` (goal band labels are the page's)
            ->options('sector', fn ($s) => $s['listing']->category, label: 'shared:suggestionListing.filters.sector', fallback: 'Sector')
            ->options('goal', fn ($s) => self::goalBand($s['listing']), label: 'shared:suggestionListing.filters.investment', fallback: 'Investment goal',
                values: array_column(self::GOAL_BANDS, 0))
            ->options('location', fn ($s) => self::placeOf($s['listing']->location), label: 'shared:suggestionListing.filters.location', fallback: 'Location', searchable: true)
            ->options('stage', fn ($s) => self::known($s['listing']->stage), label: 'app:stage')
            ->numberRange('score', fn ($s) => round((float) $s['score'], 2), label: 'app:matchScore')
            ->numberRange('amount', fn ($s) => $s['listing']->investment_needed, label: 'app:listFilterGoalAmount', fallback: 'Goal amount')
            ->numberRange('raised', fn ($s) => (float) ($s['listing']->amount_collected ?? 0), label: 'app:listFilterAmountRaised', fallback: 'Amount raised')
            ->numberRange('investors', fn ($s) => (int) ($s['listing']->invest_count ?? 0), label: 'app:investors')
            ->boolean('liked', fn ($s) => (bool) $s['listing']->liked, label: 'app:listFilterLikedByMe', fallback: 'Liked by me')
            ->dateRange('listed', fn ($s) => $s['listing']->created_at?->toDateString() ?? '', label: 'app:listFilterListedOn', fallback: 'Listed on')
            ->sortable([
                'score' => fn ($s) => (float) $s['score'],
                'goal' => fn ($s) => (float) ($s['listing']->investment_needed ?? 0),
                'investors' => fn ($s) => (int) ($s['listing']->invest_count ?? 0),
                'name' => fn ($s) => (string) $s['listing']->name,
            ], default: '-score');
        [$items, $page] = $list->fetch();

        $data = [
            'items' => $items->values(),
            'facets' => $list->facets(['sector', 'goal', 'location']),
            'filter_schema' => $list->filterSchema(),
            // false: the investor has no preferences yet, so every score is only likes and rating (the page says so)
            'stats' => ['total' => $scores->count(), 'has_preferences' => $hasPreferences],
        ];
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }
        if ($list->wantsExport()) {
            $data['export_truncated'] = $list->exportTruncated();
        }

        return response()->json(['success' => true, 'message' => 'Matches retrieved', 'data' => $data]);
    }


    public function index_s()
    {
        try{

            $scores =array();
            // Sample Data
            //$testData = new TestData();
            //$investor = $testData->investorData();
            //$listings = $testData->listingData();
            // Sample Data

            $investor = Auth::user();
            $listings = Services::withCount('liked')->get();

            $listingScore = new ServiceScore($investor);

            foreach ($listings as $listing) {
                $listing->liked = $listing->liked()->where('user_id', $investor->id)->exists();
                $score = $listingScore->calculate($listing);
                $scores[] = [
                    'score'   => $score['total'],
                    'service' => $listing,
                    'breakdown'   => $score['breakdown'],
                ];
                rsort($scores);
            }
            return response()->json(['scores' => $scores]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }


    public function index_ls($listing_id)
    {
        try{
            // Sample Data
            //$testData = new TestData();
            //$listings = $testData->listingData();

            $scores =array();
            $user_id = Auth::id();
            $serviceScore = new ListingToServiceScore();

            $listing = Listing::where('id', $listing_id)->first();
            $services = Services::withCount('liked')->latest()->get();

            $pivotData = $serviceScore->preparePivotData($listing->toArray());
            foreach ($services as $service) {
                $service->liked = $service->liked()->where('user_id', $user_id)->exists();

                $score = $serviceScore->calculate($service, $pivotData);
                $scores[] = [
                    'score'   => round($score['total'],2),
                    'service' => $service,
                    'breakdown'   => $score['breakdown'],
                    'pivotData'   => $pivotData,
                ];
                rsort($scores);
            }
            return response()->json(['scores' => $scores]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }


    public function capital_market_analysis()
    {
        try{
            $capitalAnalysis = new CapitalMarketAnalysis();
            $data = $capitalAnalysis->trendingData();
            return response()->json(['trending' => $data]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    // S u g g e s t i o n s

    public function listing_suggestions($listing_id)
    {
        try{
            $investor = Auth::user();
            $pivot_listing = Listing::find($listing_id);
            $suggested = SuggestionsForAListing::for($pivot_listing)->limit(10)->get();
            return response()->json(['listings' => $suggested]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function service_suggestions($listing_id)
    {
        try{
            $investor = Auth::user();
            $pivot_listing = Services::find($listing_id);
            $suggested = SuggestionsForAService::for($pivot_listing)->limit(10)->get();
            return response()->json(['listings' => $suggested]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function program_suggestions($program_id)
    {
        try{
            $investor = Auth::user();
            $pivot_listing = Program::find($program_id);
            $suggested = SuggestionsForAProgram::for($pivot_listing)->limit(10)->get();
            return response()->json(['programs' => $suggested]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function capital_suggestions($capital_id)
    {
        try{
            $investor = Auth::user();
            $pivot_listing = CapitalOffer::find($capital_id);
            $suggested = SuggestionsForACapital::for($pivot_listing)->limit(10)->get();
            return response()->json(['capitals' => $suggested]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


}
