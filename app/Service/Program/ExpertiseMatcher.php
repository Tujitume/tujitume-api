<?php

namespace App\Service\Program;

use App\Models\Programs\ProgramApplication;
use Illuminate\Support\Collection;

/**
 * Matches applications to Business Coaches by expertise, for a round whose owner turned on "Match Business Coaches by
 * expertise" (program_rounds.match_expertise). An application's sector is read from its business (category and type)
 * and from its answers to questions about its sector or industry. A coach matches when one of their expertise tags
 * (as set on Round Config, e.g. "Agriculture") fits those words, directly or through the common words for that area.
 * Nobody matching is not an error: the caller then shares the application out as usual.
 */
class ExpertiseMatcher
{
    /** The words that mean each of Round Config's expertise areas (lower case). Any other tag matches itself. */
    private const AREAS = [
        'agriculture' => ['agri', 'agriculture', 'agribusiness', 'farm', 'farming', 'farmer', 'crop', 'livestock', 'dairy', 'poultry', 'horticulture', 'irrigation', 'fisheries', 'fish', 'coffee', 'tea'],
        'technology' => ['tech', 'technology', 'ict', 'software', 'digital', 'app', 'it', 'internet', 'deep tech', 'innovation'],
        'finance' => ['finance', 'financial', 'fintech', 'bank', 'banking', 'sacco', 'credit', 'loan', 'savings', 'microfinance', 'insurance'],
        'healthcare' => ['health', 'healthcare', 'healthtech', 'medical', 'clinic', 'pharmacy', 'nutrition'],
        'education' => ['education', 'edtech', 'school', 'training', 'skills', 'tvet', 'learning'],
        'environment' => ['environment', 'climate', 'green', 'waste', 'recycling', 'circular', 'water', 'sanitation', 'conservation'],
        'legal' => ['legal', 'law', 'compliance'],
        'marketing' => ['marketing', 'sales', 'branding', 'advertising'],
        'operations' => ['operations', 'supply chain', 'procurement'],
        'research' => ['research', 'science', 'data'],
        'social impact' => ['social', 'impact', 'community', 'ngo', 'women', 'youth', 'cooperative', 'co-op'],
        'manufacturing' => ['manufacturing', 'processing', 'factory', 'agro-processing', 'value addition', 'jua kali'],
        'logistics' => ['logistics', 'transport', 'delivery', 'boda', 'matatu'],
        'energy' => ['energy', 'solar', 'clean energy', 'off-grid', 'cooking', 'power'],
        'real estate' => ['real estate', 'housing', 'property', 'homes', 'rental'],
        'construction' => ['construction', 'building', 'build', 'roads', 'works', 'infrastructure'],
        'retail' => ['retail', 'shop', 'trade', 'trader', 'market', 'wholesale', 'stock'],
        'tourism' => ['tourism', 'hospitality', 'hotel', 'travel', 'stays'],
        'media' => ['media', 'film', 'storytelling', 'creative', 'content'],
        'entertainment' => ['entertainment', 'music', 'arts', 'sports', 'events'],
    ];

    /** Questions whose answer says what sector an applicant is in */
    private const SECTOR_QUESTION = '/sector|industry|segment|what does your business|type of business|what do you do/i';

    /** The words describing an application's sector, lower case. */
    public function sectorOf(ProgramApplication $application): array
    {
        $words = [];
        $business = $application->business;
        foreach (['category', 'business_type'] as $field) {
            if ($business && filled($business->{$field} ?? null)) $words[] = (string) $business->{$field};
        }
        $application->loadMissing('roundAnswers.question');
        foreach ($application->roundAnswers ?? [] as $answer) {
            $text = (string) ($answer->question->question_text ?? '');
            if ($text === '' || ! preg_match(self::SECTOR_QUESTION, $text)) continue;
            // A choice list is stored as JSON ("[\"Agriculture\",\"Retail\"]"); a typed answer as plain text
            $value = (string) ($answer->response ?? '');
            $list = json_decode($value, true);
            $words[] = is_array($list) ? implode(' ', array_filter($list, 'is_scalar')) : $value;
        }

        return array_values(array_filter(array_map(fn ($w) => mb_strtolower(trim($w)), $words)));
    }

    /** Whether a coach's expertise tags fit an application's sector words. */
    public function fits(array $tags, array $sector): bool
    {
        if (! $tags || ! $sector) return false;
        $haystack = ' '.implode(' | ', $sector).' ';
        foreach ($tags as $tag) {
            $tag = mb_strtolower(trim((string) $tag));
            if ($tag === '') continue;
            foreach (array_unique([$tag, ...(self::AREAS[$tag] ?? [])]) as $word) {
                if (preg_match('/(?<![a-z])'.preg_quote($word, '/').'(?![a-z])/u', $haystack)) return true;
            }
        }

        return false;
    }

    /** A coach's expertise tags, from round_reviewers.expertise_tags (a JSON list). */
    public static function tagsOf(object $reviewer): array
    {
        $tags = $reviewer->expertise_tags ?? null;
        if (is_string($tags)) $tags = json_decode($tags, true);

        return is_array($tags) ? array_values(array_filter($tags, 'is_scalar')) : [];
    }

    /**
     * The coaches an application should be shared among: those whose expertise fits its sector, or everyone when
     * nobody fits (or the application says nothing about its sector).
     * @param Collection<int, object> $reviewers round_reviewers rows
     */
    public function candidates(Collection $reviewers, ProgramApplication $application): Collection
    {
        $sector = $this->sectorOf($application);
        $fitting = $reviewers->filter(fn ($r) => $this->fits(self::tagsOf($r), $sector))->values();

        return $fitting->isNotEmpty() ? $fitting : $reviewers->values();
    }
}
