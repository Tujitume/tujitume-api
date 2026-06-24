<?php
namespace App\Service\AI;
use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Shared\Like;
use Illuminate\Support\Collection;


class InvestorPersonalizedListing
{
    protected User $investor;

    // Hybrid weights (out of 100)
    const CONTENT_WEIGHT = 50;
    const COLLABORATIVE_WEIGHT = 30;
    const POPULARITY_WEIGHT = 20;

    public function __construct(User $investor)
    {
        $this->investor = $investor;
    }

    /**
     * Get top personalized listings for the investor
     *
     * @param int $limit
     * @return Collection
     */
    public function getTopListings(int $limit = 15): Collection
    {
        // Fetch candidate listings
        $candidateListings = Listing::withCount('liked')->where('active', 1)->get();

        // Get listings already liked by the investor
        $likedListingIds = Like::where('user_id', $this->investor->id)
            ->where('type', 'listing')
            ->pluck('listing_id')
            ->toArray();

        $results = collect();

        foreach ($candidateListings as $listing) {

            //Filter
            $listing->location = strlen($listing->location) > 30
                ? substr($listing->location, 0, 30) . '...'
                : $listing->location;
            $listing->investment_needed = number_format($listing->investment_needed);
            $listing->file = null;
            $listing->liked = in_array($listing->id, $likedListingIds);

            // 3️⃣ Content-based scoring
            $content_score = $this->calculateContentScore($listing);

            // 4️⃣ Collaborative scoring
            $collaborative_score = $this->calculateCollaborativeScore($listing, $likedListingIds);

            // 5️⃣ Popularity scoring
            $popularity_score = $this->calculatePopularityScore($listing);

            // 6️⃣ Hybrid weighted score (out of 100)
            $final_score = ($content_score * self::CONTENT_WEIGHT) +
                ($collaborative_score * self::COLLABORATIVE_WEIGHT) +
                ($popularity_score * self::POPULARITY_WEIGHT);

            $results->push([
                'listing' => $listing,
                //'liked' => in_array($listing->id, $likedListingIds),
                'content_score' => round($content_score * 100, 2),
                'collaborative_score' => round($collaborative_score * 100, 2),
                'popularity_score' => round($popularity_score * 100, 2),
                'final_score' => round($final_score, 2),
            ]);
        }

        // Sort by final_score descending and limit
        return $results->sortByDesc('final_score')->take($limit)->values();
    }

    protected function calculateContentScore(Listing $listing): float
    {
        $score = 0;

        // Sector / Category
        $interestedCats = $this->investor->interested_cats ?? [];
        if ($listing->category && in_array($listing->category, $interestedCats)) {
            $score += 0.4;
        }

        // Stage
        $stages = $this->investor->stage ?? [];
        if ($listing->stage && in_array($listing->stage, $stages)) {
            $score += 0.2;
        }

        // Social Impact Areas
        $investorImpact = $this->investor->social_impact_areas ?? [];
        $listingImpact = $listing->social_impact_areas ?? [];
        $listingImpact =is_array($listingImpact) ? $listingImpact : [];
        if (count(array_intersect($investorImpact, $listingImpact)) > 0) {
            $score += 0.2;
        }

        // Region / Location
        $regions = $this->investor->regions_focus ?? [];
        if ($listing->location && in_array($listing->location, $regions)) {
            $score += 0.2;
        }

        return min($score, 1.0); // normalize 0–1
    }

    protected function calculateCollaborativeScore(Listing $listing, array $likedListingIds): float
    {
        if (empty($likedListingIds)) return 0;

        // Find users who liked the same listings
        $similarUserIds = Like::whereIn('listing_id', $likedListingIds)
            ->where('type', 'listing')
            ->where('user_id', '!=', $this->investor->id)
            ->pluck('user_id')
            ->unique()
            ->toArray();

        if (empty($similarUserIds)) return 0;

        // Count how many similar users liked this candidate listing
        $count = Like::where('listing_id', $listing->id)
            ->whereIn('user_id', $similarUserIds)
            ->where('type', 'listing')
            ->count();

        // Normalize score 0–1
        return min($count / max(1, count($similarUserIds)), 1.0);
    }

    protected function calculatePopularityScore(Listing $listing): float
    {
        $rating_score = 0;
        if ($listing->rating_count > 0) {
            $rating_score = ($listing->rating / $listing->rating_count) / 5; // 0–1
        }
        $likes_score = min($listing->liked()->count() / 100, 1.0);
        $reviews_score = min($listing->rating_count / 50, 1.0);

        return 0.5 * $rating_score + 0.3 * $likes_score + 0.2 * $reviews_score;
    }
}
