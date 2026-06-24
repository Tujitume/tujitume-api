<?php
namespace App\Service\AiScore;
use App\Models\Services\Services;

class ListingToServiceScore
{
    const WEIGHTS = [
        'investment' => 0.10,
        //'turnover' => 0.08,
        //'stage' => 0.05,
        'impact' => 0.10,
        'sector' => 0.10,
        'region' => 0.10,
    ];


    public function preparePivotData(array $businesses): array {
        $regionCounts = [];
        $sectorCounts = [];
        $impactCounts = [];
        $investmentCounts  = [];

        foreach ($businesses as $biz) {
            $region = strtolower(trim($biz['location']));
            $regionCounts[$region] = ($regionCounts[$region] ?? 0) + 1;

            $sector = strtolower(trim($biz['category']));
            $sectorCounts[$sector] = ($sectorCounts[$sector] ?? 0) + 1;

            $investment = strtolower(trim($biz['y_turnover']));
            $investmentCounts[$investment] = ($investmentCounts[$investment] ?? 0) + 1;
            $mostCommonTurnover = array_key_first($this->getMax($investmentCounts));



            foreach ($biz['social_impact_areas'] ?? [] as $impact) {
                $impact = strtolower(trim($impact));
                $impactCounts[$impact] = ($impactCounts[$impact] ?? 0) + 1;
            }
        }

        return [
            'region'       => array_key_first($this->getMax($regionCounts)),
            'sector'       => array_key_first($this->getMax($sectorCounts)),
            'investment'   => $mostCommonTurnover,
            'impact_areas' => array_keys($this->getTop($impactCounts, 2)) // e.g. top 2 impacts
        ];
    }

// helper
    protected function getMax(array $arr): array {
        $max = max($arr);
        return array_filter($arr, fn($count) => $count === $max);
    }

    protected function getTop(array $arr, int $n): array {
        arsort($arr);
        return array_slice($arr, 0, $n, true);
    }
// helper



    protected function prepareServiceData( $listing): array
    {

        return [
            'id' => $listing->id,
            'amount_requested' => $listing->price,
            //'stage' => $listing->stage,
            'impact_areas' => array_map(fn($a) => strtolower(trim($a)), $listing->social_impact_areas ?? [] ),
            'sector' => $listing->category,
            'region' => $listing->location,
            'rating' => ($listing->rating / max($listing->rating_count, 1)),
            'likes' => $listing->likes,
        ];
    }


    public function calculate($service, $pivot)
    {
        $business = $this->prepareServiceData($service);
        $investment = $this->investmentScore($service, $pivot) * self::WEIGHTS['investment'];
        $impact     = $this->impactScore($service, $pivot)     * self::WEIGHTS['impact'];
        $sector     = $this->sectorScore($service, $pivot)     * self::WEIGHTS['sector'];
        $region     = $this->regionScore($service, $pivot)     * self::WEIGHTS['region'];
        $ratingLikes = $this->ratingAndLikesScore($business);

        $total = $investment + $impact + $sector + $region + $ratingLikes;

        return [
            'total' => $total,
            'breakdown' => [
                'investment' => $investment,
                'impact'     => $impact,
                'sector'     => $sector,
                'region'     => $region,
                'rating + Likes'     => $ratingLikes,
            ]
        ];
    }


    // S C O R E   F U N C T I O N S

    protected function investmentScore($service, $pivot) {
        if (empty($pivot['investment']) || strpos($pivot['investment'], '-') === false) {
            return 0; // or some default
        }
        [$bizMin, $bizMax] = array_map('intval', explode('-', $pivot['investment']));

        $serviceAmount = $service['amount_requested'];

        return ($serviceAmount >= $bizMin && $serviceAmount <= $bizMax) ? 100 : 0;
    }



    protected function impactScore($service, $pivot) {
        $serviceImpacts = is_array($service['impact_areas']) ? $service['impact_areas'] : json_decode($service['impact_areas'],true);
        $pivotImpacts   = is_array($pivot['impact_areas'])  ? $pivot['impact_areas']   : [] ;

        $matches = count(array_intersect(
            array_map('strtolower', $serviceImpacts ?? []),
            array_map('strtolower', $pivotImpacts ?? [])
        ));
        //echo print_r($pivotImpacts); echo '....'; echo print_r($pivotImpacts); exit;
        return $matches >= 2 ? 100 : ($matches > 0 ? 50 : 0);
    }


    protected function sectorScore($service, $pivot) {
        return  (strtolower($service['sector']) === strtolower($pivot['sector'])) ? 100 : 0;
    }


    protected function regionScore($service, $pivot) {
        $bizParts = array_map(
            fn($s) => strtolower(trim($s)),
            explode(',', $service['region'])
        );

        $pivotParts = array_map(
            fn($s) => strtolower(trim($s)),
            explode(',', $pivot['region'])
        );
        $common = array_intersect($bizParts, $pivotParts);
        return count($common) > 0 ? 100 : 0;
    }



    protected function ratingAndLikesScore($service) {
        $likedCount = Services::where('id', $service['id'])
            ->withCount('liked')
            ->value('liked_count');

        $likeScore = min(floor($likedCount / 100) + 1, 5);
        $rating = $service['rating'];
        return $likeScore+$rating;
    }
}

