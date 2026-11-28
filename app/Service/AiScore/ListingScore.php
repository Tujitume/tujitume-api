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

    /**
     * The investor's preferences. Sign-up keeps them in investor_profiles (older accounts may still have them on the
     * user). Values are kept as written at sign-up ("KES 100,000 - 250,000", "Seed", "Kenya") and compared loosely.
     */
    protected function prepareInvestorData()
    {
        $profile = $this->investor->investor_profile;
        $pick = fn (string $key, ?string $userKey = null) => self::asList($profile?->{$key} ?? $this->investor->{$userKey ?? $key} ?? []);

        $invRange = self::ranges($pick('inv_range'));
        $turnoverRange = self::ranges($pick('turnover_range'));

        $this->investorData = [
            'investment_min' => $invRange[0],
            'investment_max' => $invRange[1],
            'turnover_min' => $turnoverRange[0],
            'turnover_max' => $turnoverRange[1],
            'sectors' => array_map([self::class, 'norm'], $pick('interested_sectors', 'interested_cats')),
            'stage' => array_map([self::class, 'stageKey'], $pick('stage')),
            'impact_areas' => array_map([self::class, 'norm'], $pick('social_impact_areas')),
            'regions' => array_map([self::class, 'norm'], $pick('regions_focus')),
        ];
    }

    /** Whether the investor has told us anything to match on (the matches page asks them to set it when not) */
    public function hasPreferences(): bool
    {
        $d = $this->investorData;

        return $d['investment_max'] !== null || $d['turnover_max'] !== null || $d['sectors'] || $d['stage'] || $d['impact_areas'] || $d['regions'];
    }

    private static function asList($value): array
    {
        if (is_string($value)) $value = json_decode($value, true) ?? explode(',', $value);

        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) $value), fn ($v) => $v !== ''));
    }

    /** Lower case, letters and digits only: "Renewable-Energy" = "Renewable Energy", "Real State" = "Real Estate" */
    private static function norm($value): string
    {
        $v = trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $value)));

        return $v === 'real state' ? 'real estate' : $v;
    }

    /** One key per business stage: "Pre-seed" / "pre_seed" -> pre seed, "Early stage" -> early, "Series A+" -> series */
    private static function stageKey($value): string
    {
        $v = self::norm($value);
        if (str_starts_with($v, 'series')) return 'series';
        $aliases = ['idea stage' => 'idea', 'early stage' => 'early', 'early' => 'early', 'mvp' => 'pre seed', 'growth stage' => 'growth', 'scale' => 'expansion', 'expansion stage' => 'expansion', 'mature established' => 'mature'];

        return $aliases[$v] ?? $v;
    }

    /**
     * The lowest and highest amount of a list of ranges: "KES 100,000 - 250,000", "100000-500000", "KES 500,000+"
     * ("+" has no top). [null, null] when none can be read.
     */
    private static function ranges(array $ranges): array
    {
        $min = null; $max = null; $open = false;
        foreach ($ranges as $range) {
            $parts = preg_split('/\s*-\s*(?=\D*\d)/', preg_replace('/^[^\d]*/', '', (string) $range));
            $nums = array_values(array_filter(array_map(fn ($p) => preg_replace('/[^\d.]/', '', $p), $parts), 'strlen'));
            if (! $nums) continue;
            $low = (float) $nums[0];
            $high = isset($nums[1]) ? (float) $nums[1] : $low;
            if (str_contains((string) $range, '+')) $open = true;
            $min = $min === null ? $low : min($min, $low);
            $max = $max === null ? $high : max($max, $high);
        }

        return [$min, $open ? INF : $max];
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
            'stage' => self::stageKey($listing->stage ?? ''),
            'impact_areas' => array_map([self::class, 'norm'], self::asList($listing->social_impact_areas ?? [])),
            'sectors' => self::norm($listing->category),
            'region' => self::norm(trim(($listing->location ?? '').' '.self::countryName($listing->registration_country))),
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

    private static function countryName($code): string
    {
        $code = strtoupper(trim((string) $code));
        if (strlen($code) !== 2) return (string) $code;

        return class_exists(\Locale::class) ? (string) \Locale::getDisplayRegion('-'.$code, 'en') : $code;
    }

    /** The amount the business asks for is inside the investor's range */
    protected function investmentScore($business) {
        [$min, $max] = [$this->investorData['investment_min'], $this->investorData['investment_max']];
        if ($max === null) return 0;

        return ($business['amount_requested'] >= $min && $business['amount_requested'] <= $max) ? 100 : 0;
    }

    /** The business turnover is inside the investor's range */
    protected function turnoverScore($business) {
        [$min, $max] = [$this->investorData['turnover_min'], $this->investorData['turnover_max']];
        if ($max === null || ! $business['turnover_max']) return 0;

        return ($business['turnover_min'] >= $min && $business['turnover_max'] <= $max) ? 100 : 0;
    }

    protected function stageScore($business) {
        return $business['stage'] !== '' && in_array($business['stage'], $this->investorData['stage'], true) ? 100 : 0;
    }

    protected function impactScore($business) {
        $matches = count(array_intersect($business['impact_areas'], $this->investorData['impact_areas']));
        return $matches >= 2 ? 100 : ($matches > 0 ? 50 : 0);
    }

    protected function sectorScore($business) {
        return $business['sectors'] !== '' && in_array($business['sectors'], $this->investorData['sectors'], true) ? 100 : 0;
    }

    /** Any place the investor named (a country, county or town) is in the business location or registration country */
    protected function regionScore($business) {
        $where = ' '.$business['region'].' ';
        foreach ($this->investorData['regions'] as $place) {
            if ($place !== '' && str_contains($where, ' '.$place.' ')) return 100;
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

