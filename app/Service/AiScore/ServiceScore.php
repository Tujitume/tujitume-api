<?php
namespace App\Service\AiScore;
use App\Models\Auth\User;
use App\Models\Services\Services;

class ServiceScore
{
    const WEIGHTS = [
        'investment' => 0.10,
        //'turnover' => 0.08,
        //'stage' => 0.05,
        'impact' => 0.10,
        'sector' => 0.10,
        'region' => 0.10,
    ];

    protected User $investor;

    protected array $investorData;

    public function __construct(User $investor){
        $this->investor = $investor;
        $this->prepareInvestorData();
    }

    protected function prepareInvestorData()
    {
        $invRange = is_array($this->investor->inv_range) ? $this->investor->inv_range : [];
        $impact_areas = $this->investor->social_impact_areas ?? [];
        $items = is_array($impact_areas) ? $impact_areas : explode(',', $impact_areas);

        $this->investorData = [
            'investment_min' => collect($invRange)->flatMap(fn ($r) => explode('-', $r))->map(fn ($n) => (int) $n)->min(),
            'investment_max' => collect($invRange)->flatMap(fn ($r) => explode('-', $r))->map(fn ($n) => (int) $n)->max(),

            'sectors' => $this->investor->interested_cats ?: [],

            'impact_areas' => collect($items)
                ->flatMap(fn($a) => explode(' ', strtolower(trim($a))))
                ->filter()
                ->toArray(),
            'regions' => collect($this->investor->regions_focus ?? [])->map(fn($r) => strtolower(trim($r))),
        ];

    }


    protected function prepareServiceData( $listing): array
    {

        return [
            'id' => $listing->id,
            'amount_requested' => $listing->price,
            'stage' => $listing->stage,
            'impact_areas' => array_map(fn($a) => strtolower(trim($a)),
                is_array($listing->social_impact_areas) ? $listing->social_impact_areas : []),
            'sectors' => $listing->category,
            'region' => $listing->location,
            'rating' => ($listing->rating / max($listing->rating_count, 1)),
            'likes' => $listing->likes,
        ];
    }


    public function calculate( $listing): array
    {
        $business = $this->prepareServiceData($listing);

        $investment = $this->investmentScore($business) * self::WEIGHTS['investment'];
        $impact     = $this->impactScore($business)     * self::WEIGHTS['impact'];
        $sector     = $this->sectorScore($business)     * self::WEIGHTS['sector'];
        $region     = $this->regionScore($business)     * self::WEIGHTS['region'];
        $ratingLikes = $this->ratingAndLikesScore($business);

        $total = $investment + $impact + $sector + $region + $ratingLikes;
        $total = round(min($total, 100), 2);

        return [
            'total' => $total,
            'breakdown' => [
                'investment'   => round($investment, 2),
                'impact'       => round($impact, 2),
                'sector'       => round($sector, 2),
                'region'       => round($region, 2),
                'rating_likes' => round($ratingLikes, 2),
            ],
        ];
    }

    // S C O R E   F U N C T I O N S

    protected function investmentScore($business) {
        return ($business['amount_requested'] >= $this->investorData['investment_min'] &&
            $business['amount_requested'] <= $this->investorData['investment_max']) ? 100 : 0;
    }


//    protected function stageScore($business) {
//        return in_array($business['stage'], $this->investorData['stage']) ? 100 : 0;
//    }

    protected function impactScore($business) {
        $matches = count(array_intersect($business['impact_areas'], $this->investorData['impact_areas']));
        return $matches >= 2 ? 100 : ($matches > 0 ? 50 : 0);
    }

    protected function sectorScore($business) {
        return in_array($business['sectors'], $this->investorData['sectors']) ? 100 : 0;
    }

    protected function regionScore($business) {
        $bizParts = array_map(
            fn($s) => strtolower(trim($s)),
            explode(',', $business['region'] ?? '')
        );

        foreach ($this->investorData['regions'] as $loc) {
            $locParts = preg_split('/[\s,]+/', strtolower($loc));
            if (!empty(array_intersect($bizParts, $locParts))) {
                return 100;
            }
        }

        return 0;
    }

    protected function ratingAndLikesScore($business) {
        $likedCount = Services::where('id', $business['id'])
            ->withCount('liked')
            ->value('liked_count');

        $likeScore = min(floor($likedCount / 100) + 1, 5);
        $rating = $business['rating'];
        return $likeScore+$rating;
    }
}

