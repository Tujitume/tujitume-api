<?php

namespace App\Service\AiReview;

use App\Models\Programs\Rounds\ProgramRound;
use App\Service\Program\RoundMatchScore;
use App\Service\Program\RubricCalculator;

/**
 * Checks the model's scores for a name, an email and a phone number in code, so those never depend on how well a model reads a
 * masked value: a malformed one scores 0, and a free webmail address is only part credit for a company-email criterion.
 * The model still writes the reason for everything else.
 */
class ScoreGuard
{
    public function __construct(private RubricCalculator $rubric)
    {
    }

    /** Which kind of field a criterion or a question is about, by its words (English and Swahili). */
    public static function kind(string $text): ?string
    {
        if (preg_match('/email|e-mail|barua pepe/i', $text)) return 'email';
        if (preg_match('/phone|mobile|telephone|simu|nambari ya simu/i', $text)) return 'phone';
        if (preg_match('/full name|first and last name|first name|last name|personal name|your name|jina kamili|jina la kwanza|jina la mwisho|majina yako/i', $text)) return 'name';

        return null;
    }

    /**
     * @param array<int,array> $scores [{criterion,score,max,rationale}] from the model
     * @param array $input the review input (its sources hold the applicant's answers, with the question as label)
     * @return array<int,array> the same, with the checked ones corrected
     */
    public function apply(array $scores, ProgramRound $round, array $input): array
    {
        $answers = collect($input['sources'] ?? [])->where('type', 'answer');
        $byName = collect($round->scoring_criteria ?? [])->keyBy(fn ($c) => trim((string) ($c['name'] ?? '')));

        return array_map(function ($s) use ($answers, $byName) {
            $criterion = $byName->get(trim((string) ($s['criterion'] ?? '')));
            $kind = self::kind((string) ($s['criterion'] ?? ''));
            if (! $criterion || ! $kind) return $s;

            $answer = $this->answerFor($answers, $kind, $criterion);
            if (! $answer) return $s; // nothing to check it against: the model's score stands

            $max = $this->rubric->maxScoreFor($criterion);
            $text = trim((string) $answer['text']);
            $problem = $this->problem($kind, $text);
            if ($problem) {
                return ['score' => 0.0, 'rationale' => $problem] + $s;
            }

            // "Full name provided" / "phone number completeness" only ask whether it is there and in order: a name with a first and last name, or a
            // number with a valid country code and length, gets full credit however the model worded it
            if (in_array($kind, ['name', 'phone'], true) && preg_match('/provided|supplied|present|given|complete|completeness|imetolewa|kamili/i', (string) $s['criterion'])
                && ! preg_match('/quality|relevan|detail|depth|credib|verif/i', (string) $s['criterion'])) {
                return ['score' => (float) $max, 'rationale' => $kind === 'name' ? 'A first and last name are both given.' : 'The number has a valid country code and length.'] + $s;
            }

            // A free mailbox (Gmail, Yahoo, Outlook...) is a valid address but not a company one: part credit for a company-email criterion
            if ($kind === 'email' && preg_match('/company|business|official|organi[sz]ation|kampuni|biashara|rasmi|shirika/i', (string) $s['criterion'])) {
                $address = $this->firstEmail($text);
                if ($address && RoundMatchScore::isFreeEmailDomain(substr(strrchr($address, '@'), 1))) {
                    $partial = (float) max(1, round($max * 0.2));

                    return ['score' => min((float) $s['score'], $partial), 'rationale' => 'This is a valid email, but it is a free webmail address and not a company one, so it earns only part of the points.'] + $s;
                }
            }

            return $s;
        }, $scores);
    }

    /**
     * The answer a criterion is about. A form can ask for the same kind of thing twice (an "Email" and a "Your company email"), so the first
     * of that kind is not enough: the question named in the criterion (its description says "Based on: Q3 "Your company email"") wins, then the
     * one that shares the most words with it, and a company-email criterion prefers a question that mentions the company.
     */
    private function answerFor($answers, string $kind, array $criterion)
    {
        $candidates = $answers->filter(fn ($a) => self::kind((string) ($a['label'] ?? '')) === $kind)->values();
        if ($candidates->count() <= 1) return $candidates->first();

        $criterionText = mb_strtolower(trim(($criterion['name'] ?? '').' '.($criterion['description'] ?? '')));
        $words = fn (string $t) => array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($t)), fn ($w) => mb_strlen($w) > 2));
        $company = (bool) preg_match('/company|business|official|organi[sz]ation|kampuni|biashara|rasmi|shirika/i', $criterionText);

        return $candidates->sortByDesc(function ($a) use ($criterionText, $words, $company) {
            $label = mb_strtolower(trim((string) $a['label']));
            $score = ($label !== '' && str_contains($criterionText, $label)) ? 100 : 0;
            $score += count(array_intersect($words($label), $words($criterionText)));
            if ($company && preg_match('/company|business|official|organi[sz]ation|kampuni|biashara|rasmi|shirika/i', $label)) $score += 50;

            return $score;
        })->first();
    }

    /** Why the answer is not what was asked for, in plain words; null when it is fine. */
    private function problem(string $kind, string $text): ?string
    {
        if ($kind === 'email') {
            return $this->firstEmail($text) ? null : 'The answer is not a valid email address, so this earns no points.';
        }
        if ($kind === 'phone') {
            $digits = preg_replace('/\D+/', '', $text);
            if (strlen($digits) < 9 || strlen($digits) > 15) return 'The phone number does not have a valid length, so this earns no points.';
            if (str_starts_with($digits, '254') && ! RoundMatchScore::phoneBelongsTo($text, 'ke')) return 'This is not a valid Kenyan mobile number, so it earns no points.';

            return null;
        }
        // name: at least two words made of letters
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 2 || preg_match('/\d/', $text)) return 'The answer does not look like a first and last name, so this earns no points.';

        return null;
    }

    private function firstEmail(string $text): ?string
    {
        return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m) && filter_var($m[0], FILTER_VALIDATE_EMAIL) ? $m[0] : null;
    }
}
