<?php
namespace App\Service\AI;
use App\Models\Business\Listing;
use Illuminate\Support\Collection;

class SuggestionsForAListing
{
    protected Listing $pivot;
    protected int $limit = 6;

    public static function for(Listing $listing): self
    {
        return new self($listing);
    }

    public function __construct(Listing $listing)
    {
        $this->pivot = $listing;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function get(): Collection
    {
        $candidates = Listing::query()
            ->where('id', '!=', $this->pivot->id)
            ->where('active', 1)
            ->select('*')->get();

        return $candidates
            ->map(fn ($listing) => [
                'listing' => $listing,
                'score'   => $this->calculateScore($listing),
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
    protected function calculateScore(Listing $listing): float
    {
        $score = 0;

        // 1️⃣ Category (25)
        if ($listing->category && $listing->category === $this->pivot->category) {
            $score += 30;
        }

        // 2️⃣ Location (30)
        if ($listing->location) {
            $score += $this->locationScore($listing);
        }

        // 3️⃣ Stage (15)
        if ($listing->stage && $listing->stage === $this->pivot->stage) {
            $score += 15;
        }

        // 4️⃣ Threshold met (10)
        if ($listing->threshold_met){
            $score += 10;
        }

        // 5️⃣ Yearly turnover similarity (10)
        if ($this->turnoverMatch($listing->y_turnover)) {
            $score += 10;
        }

        // 6️⃣ Social impact overlap (5)
        if ($this->socialImpactMatch($listing->social_impact_areas)) {
            $score += 5;
        }

        // 7️⃣ Rating normalization (5)
        if ($listing->rating > 0) {
            $score += min(5, ($listing->rating / 5) * 5);
        }

        return round($score, 2);
    }

    /**
     * 📊 Turnover bucket match
     */
    protected function turnoverMatch(?string $turnover): bool
    {
        if (!$turnover || !$this->pivot->y_turnover) {
            return false;
        }

        return $turnover === $this->pivot->y_turnover;
    }

    /**
     * 🌍 Social impact JSON overlap
     */
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
    protected function locationScore(Listing $listing): int
    {
        if (!$this->pivot->lat || !$this->pivot->lng || !$listing->lat || !$listing->lng) {
            return 0;
        }

        $distance = $this->haversineDistance(
            (float)$this->pivot->lat,
            (float)$this->pivot->lng,
            (float)$listing->lat,
            (float)$listing->lng
        );

        if ($distance <= 50) {
            return 30;
        } elseif ($distance > 200) {
            return 0;
        } else {
            return max(0, 30 - ceil(($distance - 50) / 5));
        }
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

