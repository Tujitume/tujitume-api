<?php
namespace App\Service\AI;
use App\Models\Services\Services;
use Illuminate\Support\Collection;

class SuggestionsForAService
{
    protected Services $pivot;
    protected int $limit = 6;

    public static function for(Services $service): self
    {
        return new self($service);
    }

    public function __construct(Services $service)
    {
        $this->pivot = $service;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function get(): Collection
    {
        $candidates = Services::query()
            ->where('id', '!=', $this->pivot->id)
            ->where('active', 1)
            ->select('*')->get();

        return $candidates
            ->map(fn ($service) => [
                'listing' => $service,
                'score'   => $this->calculateScore($service),
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
    protected function calculateScore(Services $service): float
    {
        $score = 0;

        // 1️⃣ Category (30)
        if ($service->category && $service->category === $this->pivot->category) {
            $score += 30;
        }

        // 2️⃣ Location (30)
        if ($service->location) {
            $score += $this->locationScore($service);
        }

        // 3️⃣ Stage (10)
        if ($service->business_sector_focus && $service->business_sector_focus === $this->pivot->business_sector_focus) {
            $score += 15;
        }


        // 6️⃣ Social impact overlap (10)
        if ($this->socialImpactMatch($service->social_impact_areas)) {
            $score += 15;
        }

        // 7️⃣ Rating normalization (10)
        if ($service->rating > 0) {
            $score += min(10, ($service->rating / 5) * 10);
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
    protected function locationScore(Services $service): int
    {
        if (!$this->pivot->lat || !$this->pivot->lng || !$service->lat || !$service->lng) {
            return 0;
        }

        $distance = $this->haversineDistance(
            (float)$this->pivot->lat,
            (float)$this->pivot->lng,
            (float)$service->lat,
            (float)$service->lng
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

