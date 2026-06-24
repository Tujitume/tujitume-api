<?php
namespace App\Service\AiScore;
use App\Models\Auth\User;
use App\Models\Business\Listing;

class ListingScore
{
    const WEIGHTS = [
        'investment' => 0.10,
        'turnover' => 0.08,
        'stage' => 0.05,
        'impact' => 0.10,
        'sector' => 0.04,
        'region' => 0.03,
    ];

    protected User $investor;

    protected array $investorData;

    public function __construct(User $investor){
        $this->investor = $investor;
        $this->prepareInvestorData();
    }

    public function investorToBusiness()
    {

    }

    protected function prepareInvestorData()
    {
        $invRange = is_array($this->investor->inv_range) ? $this->investor->inv_range : [];
        $turnoverRange = is_array($this->investor->turnover_range) ? $this->investor->turnover_range : [];
        $impact_areas = $this->investor->social_impact_areas ?? [];
        $items = is_array($impact_areas) ? $impact_areas : explode(',', $impact_areas);

        $this->investorData = [
            'investment_min' => collect($invRange)->flatMap(fn ($r) => explode('-', $r))->map(fn ($n) => (int) $n)->min(),
            'investment_max' => collect($invRange)->flatMap(fn ($r) => explode('-', $r))->map(fn ($n) => (int) $n)->max(),
            'turnover_min' => collect($turnoverRange)->flatMap(fn ($r) => explode('-', $r))->map(fn ($n) => (int) $n)->min(),
            'turnover_max' => collect($turnoverRange)->flatMap(fn ($r) => explode('-', $r))->map(fn ($n) => (int) $n)->max(),
            'sectors' => $this->investor->interested_cats ?: [],
            'stage' => $this->investor->stage ?: [],
            'impact_areas' => collect($items)
                ->flatMap(fn($a) => explode(' ', strtolower(trim($a))))
                ->filter()
                ->toArray(),
            'regions' => collect($this->investor->regions_focus ?? [])->map(fn($r) => strtolower(trim($r))),
        ];

    }


    protected function prepareBusinessData( $listing): array
    {
        [$bizMin, $bizMax] = array_pad(explode('-', $listing->y_turnover ?? '0-0'), 2, 0);
        if(!$bizMax || $bizMax == 0){
            $bizMax = $bizMin;
        }

        return [
            'id' => $listing->id,
            'amount_requested' => $listing->investment_needed,
            'turnover_min' => (int) $bizMin,
            'turnover_max' => (int) $bizMax,
            'stage' => $listing->stage ?? '',
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
        $business = $this->prepareBusinessData($listing);

        $score =
        $investment = $this->investmentScore($business) * self::WEIGHTS['investment'];
        $turnover   = $this->turnoverScore($business)   * self::WEIGHTS['turnover'];
        $stage      = $this->stageScore($business)      * self::WEIGHTS['stage'];
        $impact     = $this->impactScore($business)     * self::WEIGHTS['impact'];
        $sector     = $this->sectorScore($business)     * self::WEIGHTS['sector'];
        $region     = $this->regionScore($business)     * self::WEIGHTS['region'];
        $ratingLikes = $this->ratingAndLikesScore($business);

        $total = $investment + $turnover + $stage + $impact + $sector + $region + $ratingLikes;
        $total = round(min($total, 100), 2);

        return [
            'total' => $total,
            'breakdown' => [
                'investment'   => round($investment, 2),
                'turnover'     => round($turnover, 2),
                'stage'        => round($stage, 2),
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

    protected function turnoverScore($business) {
        return ($business['turnover_min'] >= $this->investorData['turnover_min'] &&
            $business['turnover_max'] <= $this->investorData['turnover_max']) ? 100 : 0;
    }

    protected function stageScore($business) {
        return in_array($business['stage'], $this->investorData['stage']) ? 100 : 0;
    }

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
        $likedCount = Listing::where('id', $business['id'])
            ->withCount('liked')
            ->value('liked_count');

        $likeScore = min(floor($likedCount / 100) + 1, 5);
        $rating = $business['rating'];
        return $likeScore+$rating;
    }
}

