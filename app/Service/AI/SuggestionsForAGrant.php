<?php
namespace App\Service\AI;
use App\Models\Grants\Grant;
use Illuminate\Support\Collection;

class SuggestionsForAGrant
{
    protected Grant $pivot;
    protected int $limit = 6;

    public static function for(Grant $grant): self
    {
        return new self($grant);
    }

    public function __construct(Grant $grant)
    {
        $this->pivot = $grant;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function get(): Collection
    {
        $candidates = Grant::query()
            ->where('id', '!=', $this->pivot->id)
            ->where('visible', 1)
            ->select('*')->get();

        return $candidates
            ->map(fn ($grant) => [
                'grant' => $grant,
                'score'   => $this->calculateScore($grant),
            ])
            ->filter(fn ($row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($this->limit)
            //->pluck('listing')
            ->values();
    }

    /**
     * 🔢 Main scoring engine (0 – 100)
     */
    protected function calculateScore(Grant $grant): float
    {
        $score = 0;

        // 1️⃣ Category (30) / grantFocusMatch
        if ($this->grantFocusMatch($grant->grant_focus)) {
            $score += 33;
        }

        // 2️⃣ Regions (30)
        if ($this->locationScore($grant->regions)) {
            $score += 32;
        }

        // 3️⃣ Stage (20)
        if ($this->stageMatch($grant->startup_stage_focus)) {
            $score += 20;
        }


        // 6️⃣ Social impact overlap (10)
//        if ($this->socialImpactMatch($grant->social_impact_areas)) {
//            $score += 15;
//        }

        // 7️⃣ Funding normalization (15)
        if ($grant->funding_per_business > 1000) {
            $score += min(15, floor($grant->funding_per_business / 1000));
        }

        return round($score, 2);
    }


    // HELPERS
    protected function grantFocusMatch($candidateFocus): bool
    {
        if (
            empty($candidateFocus) ||
            empty($this->pivot->grant_focus)
        ) {
            return false;
        }

        $candidate = collect($candidateFocus);
        $pivot = collect($this->pivot->grant_focus);

        return $candidate->intersect($pivot)->isNotEmpty();
    }

    protected function socialImpactMatch($candidateImpact): bool
    {
        if (
            empty($candidateImpact) ||
            empty($this->pivot->social_impact_areas)
        ) {
            return false;
        }

        $candidate = collect($candidateImpact);
        $pivot = collect($this->pivot->social_impact_areas);

        return $candidate->intersect($pivot)->isNotEmpty();
    }

    //Location score based on Haversine distance
    protected function locationScore($candidateRegion): int
    {
        if (
            empty($candidateRegion) ||
            empty($this->pivot->regions)
        ) {
            return false;
        }

        $candidate = collect($candidateRegion);
        $pivot = collect($this->pivot->regions);

        return $candidate->intersect($pivot)->isNotEmpty();
    }

    protected function stageMatch($candidateStage): int
    {
        if (
            empty($candidateStage) ||
            empty($this->pivot->startup_stage_focus)
        ) {
            return false;
        }

        $candidate = collect($candidateStage);
        $pivot = collect($this->pivot->startup_stage_focus);

        return $candidate->intersect($pivot)->isNotEmpty();
    }

    /**
     * Calculate Haversine distance (miles)
     */
    protected function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $rad = pi() / 180;
        $lat1 *= $rad;
        $lng1 *= $rad;
        $lat2 *= $rad;
        $lng2 *= $rad;

        $dlng = $lng2 - $lng1;

        $distance = acos(
                sin($lat1) * sin($lat2) +
                cos($lat1) * cos($lat2) * cos($dlng)
            ) * 3958.8; // Earth radius in miles

        return round($distance, 2);
    }
}

