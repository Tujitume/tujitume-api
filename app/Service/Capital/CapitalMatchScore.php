<?php

namespace App\Service\Capital;

use App\Models\Capital\CapitalOffer;
use Illuminate\Http\Request;

/**
 * How well an application fits a capital offer (0-100), the same weights as the old app:
 *   sector 30, geo 15, stage 10, revenue 10, team 10, impact 10, milestones 10, documents 5, bonus up to +20 (capped 100).
 * The breakdown is the points each part gave (sector 30 means a full sector match).
 */
class CapitalMatchScore
{
    /** The keys of the breakdown, in the order older rows stored them as "a,b,c,d,e,f" */
    public const LEGACY_KEYS = ['sector', 'geo', 'stage', 'revenue', 'team', 'impact'];

    public static function result(float $score): string
    {
        return \App\Support\MatchTier::label($score);
    }

    /** @return array{score: float, result: string, score_breakdown: array<string, float>} */
    public function score(CapitalOffer $offer, Request $request): array
    {
        $list = fn ($v) => array_map(fn ($x) => mb_strtolower(trim((string) $x)), array_values(array_filter(is_array($v) ? $v : (json_decode((string) $v, true) ?: []), fn ($x) => is_scalar($x))));
        $in = fn ($value, array $options) => $value !== null && $value !== '' && in_array(mb_strtolower(trim((string) $value)), $options, true);

        $sectors = $list($offer->sectors);
        $regions = $list($offer->regions);
        $stages = $list($offer->startup_stage);

        $break = [
            'sector' => $in($request->input('sector'), $sectors) ? 30 : 0,
            'geo' => $in($request->input('headquarters_location'), $regions) ? 15 : 0,
            'stage' => $in($request->input('stage'), $stages) ? 10 : 0,
            'revenue' => ((float) $request->input('revenue_last_12_months', 0)) >= (float) $offer->per_startup_allocation && (float) $offer->per_startup_allocation > 0 ? 10 : 0,
            'team' => ((int) $request->input('team_experience_avg_years', 0)) >= 3 ? 10 : 0,
            'impact' => 0,
            'milestones' => 0,
            'documents' => ($request->hasFile('business_plan') || $request->hasFile('pitch_deck_file')) ? 5 : 0,
            'bonus' => 0,
        ];

        // Impact: at least two of the applicant's impact words appear in the offer's impact objectives
        $objectives = mb_strtolower((string) $offer->impact_objectives);
        $words = collect((array) $request->input('social_impact_areas', []))
            ->flatMap(fn ($area) => preg_split('/\s+/', mb_strtolower(trim((string) $area))))
            ->filter(fn ($w) => mb_strlen($w) > 2)->unique();
        $hits = $words->filter(fn ($w) => $objectives !== '' && str_contains($objectives, $w))->count();
        $break['impact'] = $hits >= 2 ? 10 : 0;

        $milestones = $request->input('milestones');
        if (is_string($milestones)) {
            $milestones = json_decode($milestones, true) ?: [];
        }
        $break['milestones'] = is_array($milestones) && count(array_filter($milestones, fn ($m) => is_array($m) && ! empty($m['title']))) > 0 ? 10 : 0;

        $bonus = $request->input('bonus_points', []);
        $bonus = is_array($bonus) ? $bonus : explode(',', (string) $bonus);
        $break['bonus'] = min(20, 5 * count(array_intersect(array_map('trim', $bonus), ['gender_led', 'youth_led', 'rural_based', 'uses_local_sourcing'])));

        $score = min(100, (float) array_sum($break));

        return ['score' => round($score, 2), 'result' => self::result($score), 'score_breakdown' => array_map(fn ($v) => (float) $v, $break)];
    }

    /** A stored breakdown (JSON object, or the old comma string) as named numbers */
    public static function parseBreakdown(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_map(fn ($v) => (float) $v, array_filter($raw, 'is_numeric'));
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_map(fn ($v) => (float) $v, array_filter($decoded, 'is_numeric'));
        }
        $parts = array_map('trim', explode(',', $raw));
        $out = [];
        foreach (self::LEGACY_KEYS as $i => $key) {
            if (isset($parts[$i]) && is_numeric($parts[$i])) {
                $out[$key] = (float) $parts[$i];
            }
        }

        return $out;
    }
}
