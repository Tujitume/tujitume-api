<?php

namespace App\Modules\ProgramImport\Readiness;

use App\Modules\ProgramImport\Registry\ImportTypeRegistry;
use App\Modules\ProgramImport\Registry\StartPointCatalog;

/**
 * Pure readiness check: no database access, so it can be demoed on fixtures.
 * $sheets = [sheet => list of normalized row value-arrays]. $existing describes a program already on the platform
 * (piecemeal imports): ['program' => [...program values], 'round_numbers' => [1,2], 'application_refs' => [...]].
 */
class ReadinessChecker
{
    public function check(string $startPoint, array $sheets, array $options = [], array $existing = []): array
    {
        $catalog = StartPointCatalog::get($startPoint);
        $landing = StartPointCatalog::landing($startPoint);
        $blockers = [];
        $warnings = [];
        $block = function (string $code, string $msg, array $ctx = []) use (&$blockers) { $blockers[] = ['code' => $code, 'message' => $msg] + $ctx; };
        $warn = function (string $code, string $msg) use (&$warnings) { $warnings[] = ['code' => $code, 'message' => $msg]; };
        $count = fn (string $s) => count($sheets[$s] ?? []);

        if (! empty($options['piecemeal'])) {
            // Adding data to a program already on the platform: only the rows given are checked.
            if (array_sum(array_map('count', $sheets)) === 0) {
                $block('no_rows', 'There are no valid rows to import.');
            }
            $catalog['required'] = [];
        }
        $label = fn (string $s) => ImportTypeRegistry::has($s) ? ImportTypeRegistry::get($s)['label'] : $s;
        foreach ($catalog['required'] as $sheet) {
            if ($count($sheet) > 0 || ($sheet === 'program' && ! empty($existing['program']))) {
                continue;
            }
            $already = (int) ($existing['already_imported'][$sheet] ?? 0);
            $invalid = (int) ($existing['invalid'][$sheet] ?? 0);
            if ($already > 0) {
                // Imported before with the same reference: the earlier records are reused, so the stage is still complete.
                $warn('already_imported', "Your {$label($sheet)} rows were already imported before (same reference), so they are not imported again. The new rows are added to what is already there. To make a separate program instead, change the reference or title in your file.");

                continue;
            }
            $invalid > 0
                ? $block('sheet_all_invalid', "Every {$label($sheet)} row has a problem. Fix the row(s) in the list below, then check again.", ['sheet' => $sheet])
                : $block('missing_sheet', "Your file has no {$label($sheet)} data. Add the \"$sheet\" sheet (it is in the template for this stage).", ['sheet' => $sheet]);
        }
        foreach (array_keys($sheets) as $sheet) {
            if (! in_array($sheet, StartPointCatalog::sheets($startPoint), true) && $count($sheet) > 0) {
                $warn('sheet_ignored', "The \"$sheet\" data is not used at this start point and will be ignored.");
            }
        }

        $program = ($sheets['program'][0] ?? null) ?: ($existing['program'] ?? []);
        $total = (float) ($program['total_program_amount'] ?? 0);
        $per = (float) ($program['funding_per_business'] ?? 0);
        $currency = $program['currency'] ?? null;

        if ($program) {
            if ($per > $total) {
                $block('funding_exceeds_total', 'Funding per business cannot exceed the total program amount.');
            }
            if (! empty($program['application_start_date']) && ! empty($program['application_deadline'])
                && $program['application_deadline'] < $program['application_start_date']) {
                $block('dates_inverted', 'The application deadline is before the start date.');
            }
            if (($program['program_type'] ?? null) === 'multi_round' && (int) ($program['total_rounds'] ?? 1) < 2) {
                $block('rounds_count', 'A multi-round program needs total rounds of 2 or more.');
            }
        }
        $maxAwardees = (int) ($program['max_awardees'] ?? ($per > 0 ? floor($total / $per) : 0));

        // Rounds
        $roundNumbers = array_map('intval', array_column($sheets['rounds'] ?? [], 'round_number')) ?: ($existing['round_numbers'] ?? []);
        if (count($roundNumbers) !== count(array_unique($roundNumbers))) {
            $block('duplicate_round', 'Two rounds share the same round number.');
        }
        if ($program && ! empty($program['total_rounds']) && $roundNumbers && count($sheets['rounds'] ?? []) > (int) $program['total_rounds']) {
            $block('too_many_rounds', 'More rounds were given than the program\'s total rounds.');
        }
        foreach ($sheets['rounds'] ?? [] as $r) {
            if (empty($r['open_date']) || empty($r['close_date'])) {
                $warn('round_dates_missing', 'Round '.($r['round_number'] ?? '?').' has no open/close date; dates are never invented.');
            } elseif ($r['close_date'] < $r['open_date']) {
                $block('round_dates_inverted', 'Round '.($r['round_number'] ?? '?').' closes before it opens.');
            }
        }

        if (in_array($startPoint, ['review', 'finalization'], true)) {
            foreach ($sheets['rounds'] ?? [] as $r) {
                $end = $r['review_period_end'] ?? $r['close_date'] ?? null;
                if ($end && $end >= date('Y-m-d')) {
                    $warn('review_period_open', 'Round '.($r['round_number'] ?? '?').' has a review period that has not ended yet; Finalize only works after it ends.');
                } elseif (! $end) {
                    $warn('review_period_missing', 'Round '.($r['round_number'] ?? '?').' has no review period end or close date; Finalize needs one in the past.');
                }
            }
        }

        // Applications
        $apps = $sheets['applications'] ?? [];
        $refs = array_column($apps, 'application_ref');
        if (count($refs) !== count(array_unique($refs))) {
            $block('duplicate_application_ref', 'Two applications share the same reference.');
        }
        foreach ($apps as $a) {
            $n = (int) ($a['round_number'] ?? 1);
            if ($roundNumbers && ! in_array($n, $roundNumbers, true)) {
                $block('unknown_round', "Application {$a['application_ref']} points to round $n which is not in the data.");
                break;
            }
        }
        if ($apps && ! array_filter(array_column($apps, 'email'))) {
            $warn('no_applicant_emails', 'No applicant emails: placeholder accounts will be created and nobody can claim them later.');
        }

        // Scores (finalization)
        if ($startPoint === 'finalization') {
            // Only the round being decided is scored; earlier rounds are already finished and take no scores
            $target = $apps ? max(array_map(fn ($a) => (int) ($a['round_number'] ?? 1), $apps)) : 1;
            $inTarget = array_column(array_filter($apps, fn ($a) => (int) ($a['round_number'] ?? 1) === $target), 'application_ref');
            $scored = array_unique(array_column($sheets['scores'] ?? [], 'application_ref'));
            $missing = array_values(array_diff($inTarget, $scored));
            if ($missing) {
                $block('missing_scores', count($missing).' application(s) have no score.', ['application_refs' => array_slice($missing, 0, 20)]);
            }
        }

        // Winners
        $winners = $sheets['winners'] ?? [];
        if ($winners) {
            if ($maxAwardees > 0 && count($winners) > $maxAwardees && empty($options['raise_max_awardees'])) {
                $block('over_max_awardees', count($winners)." awardees exceed the program's maximum of $maxAwardees. Raise max awardees or fix the data.");
            }
            $awardedSum = array_sum(array_map(fn ($w) => (float) ($w['awarded_amount'] ?? 0), $winners));
            if ($total > 0 && $awardedSum > $total) {
                $block('awarded_exceeds_total', 'Awarded amounts add up to more than the total program amount.');
            }
            if ($winners && ! array_filter(array_column($winners, 'email'))) {
                $warn('no_applicant_emails', 'No awardee emails: placeholder accounts will be created.');
            }
            if (($program['disbursement_type'] ?? null) === 'beneficiary') {
                $warn('applicant_wallet', 'Direct-to-applicant payments need each awardee to claim their account and have a wallet first.');
            }
        }

        // Milestones
        $milestones = $sheets['milestones'] ?? [];
        if ($milestones) {
            $awardedBy = [];
            foreach ($winners as $w) {
                $awardedBy[$w['application_ref'] ?? $w['startup_name'] ?? ''] = (float) $w['awarded_amount'];
            }
            $sumBy = [];
            foreach ($milestones as $m) {
                $sumBy[$m['application_ref']] = ($sumBy[$m['application_ref']] ?? 0) + (float) ($m['amount'] ?? 0);
            }
            foreach ($sumBy as $app => $sum) {
                if (isset($awardedBy[$app])) {
                    if ($sum > $awardedBy[$app] + 0.005) {
                        $block('milestones_exceed_award', "Milestones for $app add up to more than the awarded amount.");
                    } elseif ($sum < $awardedBy[$app] - 0.005) {
                        $warn('milestones_below_award', "Milestones for $app add up to less than the awarded amount.");
                    }
                }
            }
            $isPaid = fn ($m) => ($m['fund_release_status'] ?? null) === 'released' || ($m['status'] ?? null) === 'completion_approved';
            $released = array_filter($milestones, $isPaid);
            $openLeft = array_filter($milestones, fn ($m) => ! $isPaid($m));
            if ($startPoint === 'disbursement' && $released && $openLeft) {
                // Escrow funding now covers only the unreleased remainder (core fix, approved), so a partly paid application is allowed.
                $warn('partial_release_remainder', 'Some milestones are already paid. Funding escrow will cover only the remaining milestones.');
            }
            $seq = [];
            foreach ($milestones as $m) {
                $k = $m['application_ref'].'#'.$m['sequence_order'];
                if (isset($seq[$k])) {
                    $block('duplicate_sequence', "Two milestones share sequence {$m['sequence_order']} for {$m['application_ref']}.");
                    break;
                }
                $seq[$k] = true;
            }
        }
        if ($sheets['disbursements'] ?? []) {
            foreach ($sheets['disbursements'] as $d) {
                if (($program['disbursement_type'] ?? null) === 'supplier' && empty($d['supplier_ref']) && ($d['recipient_type'] ?? 'supplier') === 'supplier') {
                    $warn('supplier_missing', 'Some disbursements have no supplier; supplier payments need supplier details.');
                    break;
                }
            }
        }
        if ($currency && ! in_array($currency, ['KES', 'USD'], true)) {
            $block('currency_unsupported', "Currency $currency is not supported (KES or USD).");
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'counts' => array_map('count', array_filter($sheets, 'is_array')),
            'landing_preview' => $this->preview($startPoint, $landing, $sheets, $roundNumbers, $winners, $milestones),
            'skipped' => $landing->skipped(),
            'next_steps' => $catalog['next_steps'],
        ];
    }

    private function preview(string $sp, $landing, array $sheets, array $roundNumbers, array $winners, array $milestones): array
    {
        $apps = $sheets['applications'] ?? [];
        $target = $roundNumbers ? max(array_map(fn ($a) => (int) ($a['round_number'] ?? 1), $apps) ?: [min($roundNumbers)]) : null;
        $rounds = [];
        foreach ($roundNumbers ?: [] as $n) {
            $rounds[$n] = $landing->roundStatus($n, in_array($sp, ['review', 'finalization'], true) ? $target : null);
        }
        $byStatus = [];
        foreach ($apps as $a) {
            $v = $landing->application((int) ($a['round_number'] ?? 1), $sp === 'finalization');
            $key = ($v['status'] ?? '-').' / '.($v['round_status'] ?? '-');
            $byStatus[$key] = ($byStatus[$key] ?? 0) + 1;
        }
        if ($winners) {
            $v = $landing->application(1, false);
            $byStatus[($v['status'] ?? '-').' / '.($v['round_status'] ?? '-')] = count($winners);
        }
        $allDone = $milestones && ! array_filter($milestones, fn ($m) => ! in_array($m['status'] ?? 'pending', ['completion_approved'], true));

        $perRound = [];
        foreach ($apps as $a) {
            $n = (int) ($a['round_number'] ?? 1);
            $perRound[$n] = ($perRound[$n] ?? 0) + 1;
        }
        ksort($perRound);

        return [
            'program_title' => $sheets['program'][0]['program_title'] ?? null,
            'applications_by_round' => $perRound,
            'questions' => count($sheets['questions'] ?? []),
            'awardees' => count($winners),
            'payments' => count($sheets['disbursements'] ?? []),
            'checkpoints' => count($sheets['checkpoints'] ?? []),
            'program' => $landing->program((bool) $allDone),
            'rounds' => $rounds,
            'applications' => $byStatus,
            'milestones' => $milestones ? ['count' => count($milestones), 'sample' => $landing->milestone(1)] : ['count' => 0],
            'description' => $landing->describe(),
        ];
    }
}
