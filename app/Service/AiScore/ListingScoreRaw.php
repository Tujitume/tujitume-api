<?php
namespace App\Service\AiScore;
use App\Models\Business\Listing;
use Illuminate\Support\Facades\Auth;

Class ListingScoreRaw
{
    public function ListingScoreRaw()
    {
        try {
            $investor = Auth::user();
            $listings = Listing::all();
            foreach ($listings as $listing) {

                // Input data
                [$bizMin, $bizMax] = explode('-', $listing->y_turnover);

                $business= [
                    'amount_requested' => $listing->investment_needed ?: [],
                    'turnover_min' => (int) $bizMin,
                    'turnover_max' => (int) $bizMax,
                    'stage' => $listing->stage,
                    'impact_score' => collect(explode(',', $listing->social_impact_areas))
                        ->flatMap(fn($area) => explode(' ', strtolower(trim($area)))),
                    'sectors' => $listing->category,
                    'region' => $listing->location,
                    'rating' => ($listing->rating/$listing->rating_count),
                    //'like' => $listing->like,
                ];

                $inv_range_raw = collect($investor->inv_range, true)
                    ->flatMap(fn ($r) => explode('-', $r))
                    ->map(fn ($n) => (int) $n);

                $turnover_range_raw = collect($investor->turnover_range, true)
                    ->flatMap(fn ($r) => explode('-', $r))
                    ->map(fn ($n) => (int) $n);

                $investor = [
                    'investment_range_min' => $inv_range_raw->min(),
                    'investment_range_max' => $inv_range_raw->max(),
                    'turnover_min' => $turnover_range_raw->min(),
                    'turnover_max' => $turnover_range_raw->max(),

                    'sectors' => $investor->interested_cats ?: [],
                    'stage' => $investor->stage ?: [],
                    'impact_score' => collect(explode(',', $investor->social_impact_areas))
                        ->flatMap(fn($area) => explode(' ', strtolower(trim($area)))),
                    'regions_focus' => explode(',', $investor->regions_focus) ?: [],

//                    'sectors' => json_decode($investor->interested_cats, true) ?: [],
//                    'stage' => json_decode($investor->stage, true) ?: [],
//                    'impact_score' => collect(explode(',', $investor->social_impact_areas))
//                        ->flatMap(fn($area) => explode(' ', strtolower(trim($area)))),
//                    'regions_focus' => explode(',', $investor->regions_focus) ?: [],
                ];

                $score = 0;
                //Investment range 10%
                $rangeScore = (
                    $business['amount_requested'] >= $investor['investment_range_min']
                    && $business['amount_requested'] <= $investor['investment_range_max']
                ) ? 100 : 0;

                $score += $rangeScore * 0.10;


                //Turnover range 8%
                $turnoverScore = (
                    $business['turnover_min'] >= $investor['turnover_min'] &&
                    $business['turnover_max'] <= $investor['turnover_max']
                ) ? 100 : 0;

                $score += $turnoverScore * 0.08;

                // Stage Compatibility (10%)
                $stageScore = in_array($business['stage'], $investor['stage']) ? 100 : 0;
                $score += $stageScore * 0.05;

                // Impact Focus (10%)
                $matchCount = 0;
                foreach ($investor['social_impact_areas'] as $item) {
                    $matchCount += substr_count($business['social_impact_areas'], $item);
                }
                $impactScore = $matchCount >= 2 ? 100 : ($matchCount > 0 ? 50 : 0);
                $score += $impactScore * 0.10;

                // Sector Alignment (30%)
                $sectorScore = in_array($business['sectors'], $investor['sectors']) ? 100 : 0;;
                $score += $sectorScore * 0.04;

                // Geographic Fit (15%)
                //$geoScore = in_array($business['region'], $org['target_regions']) ? 100 : 0; $score += $geoScore * 0.15;

                $regions = array_map('trim', explode(',', $business['region']));
                $bizCity = strtolower($regions[count($regions) - 2] ?? '');
                $matchFound = collect($investor['regions_focus'])->contains(fn($loc) =>
                in_array($bizCity, preg_split('/[\s,]+/', strtolower($loc)))
                );
                $regionScore = $matchFound ? 100: 0;
                $score += $regionScore * 0.03;


                // Bonus (up to +10) Rating + Likes
                $score += $business['rating']; $bo = 0;

                //$likeScore = min(floor($business['likes'] / 100) + 1, 5);
                //$score += $likeScore;

                $score = min($score, 100);

                // Final Result
                //if ($score >= 80) {$result = "Ideal Match";} elseif ($score >= 60) {$result = "Strong Match";}
                //else {$result = "Needs Revision";}


                return response()->json([
                    'score' => round($score, 2),
                    'result' => $result,
                    'score_breakdown' => [
                        'Sector Alignment' => round($sectorScore * 0.30, 2),
                        'Geographic Fit' => round($geoScore * 0.15, 2),
                        'Startup Stage Compatibility' => round($stageScore * 0.10, 2),
                        'Revenue Traction' => round($revenueScore * 0.10, 2),
                        'Impact Focus' => round($impactScore * 0.10, 2),
                        'Milestone Success' => round($milestoneScore * 0.10, 2),
                        'Document Completeness' => round($documentScore * 0.05, 2),
                        'Bonus ' => $bo
                    ],
                    //'value_sent' => $request->all(),
                    //'value_compare' => $value_compare
                ]);
            }
            return response()->json(['pitches' => $pitches], 200);
        }
        catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
