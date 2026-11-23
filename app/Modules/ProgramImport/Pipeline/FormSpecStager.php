<?php

namespace App\Modules\ProgramImport\Pipeline;

use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Support\GoogleFormFetcher;
use App\Modules\ProgramImport\Support\GoogleFormParser;
use App\Modules\ProgramImport\Support\TextFormParser;

/**
 * Form imports: build form_spec (suggested questions), then turn the confirmed spec into ordinary staged rows
 * (program + round 1 + questions) so validation, readiness and commit are the same pipeline as every other import.
 */
class FormSpecStager
{
    public function __construct(private ?\App\Service\AiReview\LlmClient $client = null) {}

    public function extract(ImportBatch $batch, ?string $url = null, ?string $text = null, bool $viaGoogleApi = false): void
    {
        if ($viaGoogleApi) {
            if (! \App\Modules\ProgramImport\Support\GoogleConnection::configured()) {
                throw new \App\Modules\ProgramImport\Support\FormFetchException('google_not_configured');
            }
            $spec = (new \App\Modules\ProgramImport\Support\GoogleConnection)->fetchForm((int) $batch->organization_id, (string) $url);
            $spec['program'] = ['program_title' => $spec['title'] ?? 'Imported program', 'program_description' => $spec['description'] ?? null] + ($batch->form_spec['program'] ?? []);
            $spec += ['required_documents' => [], 'knockout_suggestions' => [], 'scoring_suggestions' => [], 'confirmed' => false];
            $spec = self::tidy($spec);
            $batch->update(['form_spec' => $spec, 'status' => 'mapped']);

            return;
        }
        $ai = null;
        // A copied Google Form page is read exactly (required marks, help, options) without AI
        $googleCopy = $text !== null && preg_match('/indicates required question/i', $text);
        if ($text !== null && ! $googleCopy && ($client = $this->client ?? \App\Modules\ProgramImport\Mapping\AiMapper::resolveClient())) {
            $ai = (new \App\Modules\ProgramImport\Mapping\AiFormExtractor($client))->extract($text);
            $batch->update(['ai_usage' => ['calls' => 1, 'used' => (bool) $ai, 'task' => 'extract_form_spec']]);
        }
        if ($ai === null && $url) {
            try {
                $spec = (new GoogleFormParser)->parse((new GoogleFormFetcher)->fetch($url));
            } catch (\App\Modules\ProgramImport\Support\FormFetchException $e) {
                // A form that needs a sign-in can be read through the organization's connected Google account
                if (! in_array($e->getMessage(), ['form_private', 'form_empty'], true) || ! \App\Modules\ProgramImport\Support\GoogleConnection::configured()) {
                    throw $e;
                }
                $spec = (new \App\Modules\ProgramImport\Support\GoogleConnection)->fetchForm((int) $batch->organization_id, $url);
            }
        }
        $spec = $spec ?? $ai ?? (new TextFormParser)->parse((string) $text);
        $spec = self::tidy($spec);
        $spec['program'] = ['program_title' => $spec['title'] ?? 'Imported program', 'program_description' => $spec['description'] ?? null] + ($batch->form_spec['program'] ?? []);
        $spec['required_documents'] = $spec['required_documents'] ?? [];
        $spec['knockout_suggestions'] = $spec['knockout_suggestions'] ?? [];
        $spec['scoring_suggestions'] = $spec['scoring_suggestions'] ?? [];
        $spec['confirmed'] = false;
        $batch->update(['form_spec' => $spec, 'status' => 'mapped']);
    }

    /** Turns the (edited) spec into staged rows. Questions with include=false are left out. */
    public function stage(ImportBatch $batch): void
    {
        $spec = $batch->form_spec ?? [];
        ImportRow::where('batch_id', $batch->id)->delete();
        $n = 0;
        $add = function (string $sheet, array $raw) use ($batch, &$n) {
            ImportRow::create(['batch_id' => $batch->id, 'sheet' => $sheet, 'row_number' => ++$n, 'raw' => $raw, 'status' => 'pending']);
        };
        $questions = array_values(array_filter($spec['questions'] ?? [], fn ($q) => ($q['include'] ?? true) !== false));
        $names = [];
        foreach ($spec['rounds'] ?? [] as $r) {
            $names[(int) ($r['number'] ?? 0)] = (string) ($r['name'] ?? '');
        }
        $numbers = array_unique(array_merge([1], array_map(fn ($q) => max(1, (int) ($q['round'] ?? 1)), $questions), array_keys(array_filter($names))));
        sort($numbers);
        // Rounds are numbered 1..n in order, so a gap ("Round 1", "Round 3") never leaves an empty round
        $renumber = array_flip($numbers);
        $program = array_merge($spec['program'] ?? [], ['program_ref' => 'form']);
        if (count($numbers) > 1) {
            $program['program_type'] = 'multi_round';
            $program['total_rounds'] = max(count($numbers), (int) ($program['total_rounds'] ?? 0));
        }
        $add('program', $program);
        foreach ($numbers as $n) {
            $pos = $renumber[$n] + 1;
            $add('rounds', ['program_ref' => 'form', 'round_number' => $pos, 'round_name' => ($names[$n] ?? '') !== '' ? $names[$n] : ($pos === 1 ? 'Application' : "Round $pos"),
                'required_documents' => $pos === 1 ? implode('; ', $spec['required_documents'] ?? []) : null]);
        }
        foreach ($questions as $i => $q) {
            $pos = $renumber[max(1, (int) ($q['round'] ?? 1))] + 1;
            $add('questions', ['round_ref' => 'round-'.$pos, 'question_ref' => 'q'.($i + 1), 'question_text' => $q['label'], 'question_type' => $q['type'] ?? 'short_answer',
                'is_required' => ! empty($q['required']) ? 'yes' : 'no', 'options' => implode('; ', $q['options'] ?? []), 'help_text' => $q['help_text'] ?? null]);
        }
        $identity = ['sheets' => []];
        foreach (['program', 'rounds', 'questions'] as $s) {
            $identity['sheets'][$s] = ['columns' => [], 'ignored' => [], 'value_maps' => [], 'defaults' => []];
        }
        $batch->update(['mapping' => $identity, 'stats' => array_merge($batch->stats ?? [], ['sheets' => ['program' => ['rows' => 1], 'rounds' => ['rows' => count($numbers)], 'questions' => ['rows' => count($questions)]], 'rows_total' => $n])]);
    }

    /** Every question gets a round-setup type, a round number and its options; the round list covers every round used. */
    public static function tidy(array $spec, bool $refine = true): array
    {
        $rounds = [];
        foreach ($spec['rounds'] ?? [] as $r) {
            $rounds[(int) ($r['number'] ?? 1)] = $r['name'] ?? ('Round '.(int) ($r['number'] ?? 1));
        }
        $spec['questions'] = array_values(array_map(function ($q) use (&$rounds, $refine) {
            $options = array_values(array_filter(array_map(fn ($o) => is_string($o) ? trim($o) : null, (array) ($q['options'] ?? []))));
            $type = \App\Modules\ProgramImport\Importers\QuestionImporter::appType($q['type'] ?? null);
            $inferred = TextFormParser::inferType((string) ($q['label'] ?? ''), (string) ($q['help_text'] ?? ''), $options);
            // generic types are refined (an "email" question typed "short answer" becomes email)
            if (! $type || $refine && ($type === 'short_answer' && $inferred !== 'long_text') || ($type === 'long_text' && in_array($inferred, ['email', 'phone', 'url', 'video_link', 'file_upload', 'signature'], true))) {
                $type = $inferred;
            }
            if (in_array($type, \App\Modules\ProgramImport\Importers\QuestionImporter::OPTION_TYPES, true) && count($options) < 1) {
                $type = 'short_answer';
            }
            $round = max(1, min(10, (int) ($q['round'] ?? 1)));
            $rounds[$round] ??= $round === 1 ? 'Application' : "Round $round";

            return array_merge($q, ['type' => $type, 'options' => $options, 'round' => $round]);
        }, $spec['questions'] ?? []));
        $rounds[1] ??= 'Application';
        ksort($rounds);
        $spec['rounds'] = array_map(fn ($n, $name) => ['number' => $n, 'name' => $name], array_keys($rounds), array_values($rounds));

        return $spec;
    }
}
