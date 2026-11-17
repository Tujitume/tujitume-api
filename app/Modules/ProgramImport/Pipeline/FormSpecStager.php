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
            $batch->update(['form_spec' => $spec, 'status' => 'mapped']);

            return;
        }
        $ai = null;
        if ($text !== null && ($client = $this->client ?? \App\Modules\ProgramImport\Mapping\AiMapper::resolveClient())) {
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
        $program = array_merge($spec['program'] ?? [], ['program_ref' => 'form']);
        $add('program', $program);
        $add('rounds', ['program_ref' => 'form', 'round_number' => 1, 'round_name' => 'Application', 'required_documents' => implode('; ', $spec['required_documents'] ?? [])]);
        foreach (array_values(array_filter($spec['questions'] ?? [], fn ($q) => ($q['include'] ?? true) !== false)) as $i => $q) {
            $add('questions', ['round_ref' => 'round-1', 'question_ref' => 'q'.($i + 1), 'question_text' => $q['label'], 'question_type' => $q['type'] ?? 'text',
                'is_required' => ! empty($q['required']) ? 'yes' : 'no', 'options' => implode('; ', $q['options'] ?? []), 'help_text' => $q['help_text'] ?? null]);
        }
        $identity = ['sheets' => []];
        foreach (['program', 'rounds', 'questions'] as $s) {
            $identity['sheets'][$s] = ['columns' => [], 'ignored' => [], 'value_maps' => [], 'defaults' => []];
        }
        $batch->update(['mapping' => $identity, 'stats' => array_merge($batch->stats ?? [], ['sheets' => ['program' => ['rows' => 1], 'rounds' => ['rows' => 1], 'questions' => ['rows' => $n - 2]], 'rows_total' => $n])]);
    }
}
