<?php

namespace App\Modules\ProgramImport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponseResource;
use App\Models\Programs\Program;
use App\Modules\ProgramImport\Http\Requests\StoreBatchRequest;
use App\Modules\ProgramImport\Http\Resources\BatchResource;
use App\Modules\ProgramImport\Jobs\AnalyzeBatchJob;
use App\Modules\ProgramImport\Jobs\CommitBatchJob;
use App\Modules\ProgramImport\Jobs\ParseBatchJob;
use App\Modules\ProgramImport\Jobs\ValidateBatchJob;
use App\Modules\ProgramImport\Mapping\MappingApplier;
use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Normalizers\Normalizer;
use App\Modules\ProgramImport\Parsing\FileGuard;
use App\Modules\ProgramImport\Pipeline\BatchStager;
use App\Modules\ProgramImport\Pipeline\BatchValidator;
use App\Modules\ProgramImport\Pipeline\FormSpecStager;
use App\Modules\ProgramImport\Pipeline\ReadinessService;
use App\Modules\ProgramImport\Policies\ImportAccess;
use App\Modules\ProgramImport\Registry\ImportTypeRegistry;
use App\Modules\ProgramImport\Rollback\RollbackGuard;
use App\Modules\ProgramImport\Rollback\Rollbacker;
use App\Modules\ProgramImport\Support\Audit;
use App\Modules\ProgramImport\Support\FormFetchException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BatchController extends Controller
{
    private const EDITABLE = ['uploaded', 'parsing', 'needs_mapping', 'mapped', 'validating', 'ready', 'has_errors', 'failed'];

    /** Batches are looked up inside the caller's organization, so another organization's batch is simply a 404. */
    private function batch(Request $r, int $id, bool $write): ImportBatch
    {
        $orgId = ImportAccess::organizationId($r->user());
        $b = ImportBatch::where('organization_id', $orgId)->findOrFail($id);
        abort_unless($write ? ImportAccess::canWrite($r->user()) : ImportAccess::canRead($r->user()), 403);

        return $b;
    }

    private function fail(string $msg, string $code, int $status = 422, array $extra = [])
    {
        return ApiResponseResource::error($msg, ['code' => $code] + $extra, $status);
    }

    public function index(Request $r)
    {
        if ($denial = ImportAccess::readDenial($r->user())) {
            return ApiResponseResource::error('Program import access denied.', $denial, 403);
        }
        $p = ImportBatch::where('organization_id', ImportAccess::organizationId($r->user()))->latest('id')->paginate(min((int) $r->query('per_page', 20), 100));

        return ApiResponseResource::success('Imports', ['items' => BatchResource::collection($p->items())->resolve(), 'meta' => ['total' => $p->total(), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()]]);
    }

    public function show(Request $r, int $batch)
    {
        return ApiResponseResource::success('Import', (new BatchResource($this->batch($r, $batch, false)))->resolve());
    }

    public function store(StoreBatchRequest $r)
    {
        $orgId = ImportAccess::organizationId($r->user());
        $v = $r->validated();
        $source = $v['source'];
        $type = $v['type'] ?? $v['start_point'] ?? null;
        $start = $v['start_point'] ?? null;
        if (in_array($source, ['google_form_url', 'google_api'], true) || ($source === 'text' && ($v['type'] ?? null) === 'form')) {
            $type = 'form';
            $start = 'setup';
        }
        if ($type !== 'form' && ! ImportTypeRegistry::has($type) && ! $start) {
            return $this->fail('Choose a start point.', 'invalid_type');
        }
        if (! empty($v['program_id']) && ! Program::where('id', $v['program_id'])->where('organization_id', $orgId)->exists()) {
            return $this->fail('That program was not found in your organization.', 'program_not_found', 404);
        }
        // Target round for imports into an existing program: by id (must belong to that program) or by number
        $roundNumber = $v['round_number'] ?? null;
        if (! empty($v['round_id'])) {
            $round = \App\Models\Programs\Rounds\ProgramRound::where('id', $v['round_id'])->whereHas('program', fn ($q) => $q->where('organization_id', $orgId))->first();
            if (! $round || (! empty($v['program_id']) && (int) $round->program_id !== (int) $v['program_id'])) {
                return $this->fail('That round was not found in the program.', 'round_not_found', 404);
            }
            $roundNumber = (int) $round->round_number;
            $v['program_id'] ??= $round->program_id;
        }
        if ($roundNumber && empty($v['program_id'])) {
            return $this->fail('A target round needs a program.', 'program_required');
        }
        $v['options'] = array_merge($v['options'] ?? [], $roundNumber ? ['round_number' => (int) $roundNumber] : []);
        $batch = ImportBatch::create([
            'organization_id' => $orgId, 'user_id' => $r->user()->id, 'program_id' => $v['program_id'] ?? null, 'start_point' => $start,
            'type' => $type, 'source' => $source, 'status' => 'uploaded', 'options' => array_merge(BatchValidator::DEFAULTS, $v['options'] ?? []),
        ]);

        if ($type === 'form') {
            try {
                (new FormSpecStager)->extract($batch, in_array($source, ['google_form_url', 'google_api'], true) ? $v['url'] : null, $source === 'text' ? $v['text'] : null, $source === 'google_api');
            } catch (FormFetchException $e) {
                $batch->update(['status' => 'failed', 'error_summary' => ['code' => $e->getMessage()]]);

                return $this->fail($this->formMessage($e->getMessage()), $e->getMessage(), 422, ['fallbacks' => ['upload a file', 'paste the questions as text']]);
            }

            return ApiResponseResource::success('Form read. Review the suggested questions.', (new BatchResource($batch->refresh()))->resolve(), 201);
        }

        if ($source === 'text') {
            $path = 'program-imports/'.$orgId.'/'.Str::uuid().'.csv';
            BatchStager::disk()->put($path, $v['text']);
            $batch->update(['file_path' => $path, 'original_filename' => 'pasted.csv', 'file_hash' => hash('sha256', $v['text'])]);
        } else {
            $check = FileGuard::inspect($r->file('file'));
            if (! $check['ok']) {
                $batch->delete();

                return $this->fail($check['error'], 'invalid_file');
            }
            $path = 'program-imports/'.$orgId.'/'.Str::uuid().'.'.$check['ext'];
            BatchStager::disk()->putFileAs(dirname($path), $r->file('file'), basename($path));
            $batch->update(['file_path' => $path, 'original_filename' => mb_substr($r->file('file')->getClientOriginalName(), 0, 250), 'file_hash' => hash_file('sha256', $r->file('file')->getRealPath())]);
        }
        if ($zip = $r->file('attachments')) {
            $head = (string) file_get_contents($zip->getRealPath(), false, null, 0, 4);
            if (strtolower($zip->getClientOriginalExtension()) !== 'zip' || ! str_starts_with($head, 'PK')) {
                $batch->update(['status' => 'failed']);

                return $this->fail('The attachments must be a ZIP file.', 'invalid_attachments');
            }
            $apath = 'program-imports/'.$orgId.'/'.Str::uuid().'-attachments.zip';
            BatchStager::disk()->putFileAs(dirname($apath), $zip, basename($apath));
            $batch->update(['options' => array_merge($batch->options ?? [], ['attachments_path' => $apath])]);
        }
        Audit::log($batch, $r->user()->id, 'uploaded', ['source' => $source, 'filename' => $batch->original_filename]);
        ParseBatchJob::dispatch($batch->id);

        return ApiResponseResource::success('Import started.', (new BatchResource($batch->refresh()))->resolve(), 201);
    }

    private function formMessage(string $code): string
    {
        return ['form_private' => 'This form needs a sign-in, so it cannot be read. Upload its questions as a file or paste them as text.', 'form_url' => 'Use a public Google Forms link.', 'form_unreachable' => 'The form could not be reached.', 'form_empty' => 'No questions were found.', 'form_too_large' => 'The form is too large.', 'too_many_redirects' => 'The link redirects too many times.', 'google_not_connected' => 'Connect your Google account to read forms that need a sign-in.', 'google_not_configured' => 'Connecting Google is not configured on this server.'][$code] ?? 'The form could not be read.';
    }

    public function analyze(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, true);
        if (! in_array($b->status, ['needs_mapping', 'mapped', 'ready', 'has_errors'], true)) {
            return $this->fail('This import is not ready to be analyzed.', 'invalid_state');
        }
        if ($b->type !== 'form') {
            AnalyzeBatchJob::dispatch($b->id);
        }

        return ApiResponseResource::success('Analysis started.', (new BatchResource($b->refresh()))->resolve(), 202);
    }

    public function mapping(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, true);
        if (! in_array($b->status, self::EDITABLE, true) || $b->type === 'form') {
            return $this->fail('The mapping cannot be changed now.', 'invalid_state');
        }
        $in = $r->validate(['sheets' => ['nullable', 'array'], 'columns' => ['nullable', 'array'], 'value_maps' => ['nullable', 'array'], 'defaults' => ['nullable', 'array'], 'ignored' => ['nullable', 'array']]);
        $sheets = $in['sheets'] ?? (ImportTypeRegistry::has($b->type) ? [$b->type => $in] : []);
        $mapping = $b->mapping ?? ['sheets' => []];
        foreach ($sheets as $sheet => $m) {
            if (! ImportTypeRegistry::has($sheet)) {
                return $this->fail("Unknown sheet \"$sheet\".", 'invalid_sheet');
            }
            $mapping['sheets'][$sheet] = MappingApplier::sanitize($sheet, (array) $m);
        }
        $b->update(['mapping' => $mapping, 'status' => 'mapped', 'readiness' => null, 'landing_preview' => null]);
        Audit::log($b, $r->user()->id, 'mapping_saved');

        return ApiResponseResource::success('Mapping saved.', (new BatchResource($b->refresh()))->resolve());
    }

    public function formSpec(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, true);
        if ($b->type !== 'form' || ! in_array($b->status, self::EDITABLE, true)) {
            return $this->fail('This import has no form to edit.', 'invalid_state');
        }
        $in = $r->validate([
            'program' => ['nullable', 'array'], 'questions' => ['nullable', 'array', 'max:200'],
            'questions.*.label' => ['required', 'string', 'max:500'], 'questions.*.type' => ['required', Rule::in(array_merge(\App\Modules\ProgramImport\Importers\QuestionImporter::APP_TYPES, array_keys(\App\Modules\ProgramImport\Importers\QuestionImporter::ALIASES)))],
            'questions.*.round' => ['nullable', 'integer', 'min:1', 'max:10'], 'rounds' => ['nullable', 'array', 'max:10'], 'rounds.*.number' => ['required', 'integer', 'min:1', 'max:10'], 'rounds.*.name' => ['nullable', 'string', 'max:120'],
            'questions.*.required' => ['nullable', 'boolean'], 'questions.*.options' => ['nullable', 'array'], 'questions.*.include' => ['nullable', 'boolean'],
            'questions.*.help_text' => ['nullable', 'string', 'max:1000'], 'required_documents' => ['nullable', 'array', 'max:30'], 'required_documents.*' => ['string', 'max:100'],
            'confirmed' => ['nullable', 'boolean'],
        ]);
        $spec = $b->form_spec;
        if (isset($in['program'])) {
            $allowed = array_keys(ImportTypeRegistry::fields('program'));
            $spec['program'] = array_merge($spec['program'] ?? [], array_map(fn ($x) => is_scalar($x) ? Normalizer::text($x)['value'] ?? $x : null, array_intersect_key($in['program'], array_flip($allowed))));
        }
        if (isset($in['questions'])) {
            $spec['questions'] = array_map(fn ($q) => array_merge(['maps_to' => null, 'confidence' => 1.0, 'suggested' => false], $q, ['label' => Normalizer::text($q['label'])['value'], 'options' => array_map(fn ($o) => Normalizer::text($o)['value'], $q['options'] ?? [])]), $in['questions']);
        }
        isset($in['rounds']) && $spec['rounds'] = array_map(fn ($x) => ['number' => (int) $x['number'], 'name' => Normalizer::text($x['name'] ?? '')['value'] ?? ''], $in['rounds']);
        $spec = FormSpecStager::tidy($spec, false);
        isset($in['required_documents']) && $spec['required_documents'] = array_map(fn ($d) => Normalizer::text($d)['value'], $in['required_documents']);
        isset($in['confirmed']) && $spec['confirmed'] = (bool) $in['confirmed'];
        $b->update(['form_spec' => $spec, 'status' => 'mapped', 'readiness' => null]);
        Audit::log($b, $r->user()->id, 'form_spec_saved');

        return ApiResponseResource::success('Form saved.', (new BatchResource($b->refresh()))->resolve());
    }

    public function validateBatch(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, true);
        if (! in_array($b->status, ['mapped', 'ready', 'has_errors'], true)) {
            return $this->fail('Map the columns first.', 'invalid_state');
        }
        Audit::log($b, $r->user()->id, 'validation_started');
        ValidateBatchJob::dispatch($b->id);

        return ApiResponseResource::success('Validation started.', (new BatchResource($b->refresh()))->resolve(), 202);
    }

    public function readiness(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, true);
        if (! in_array($b->status, ['ready', 'has_errors'], true)) {
            return $this->fail('Validate the import first.', 'not_validated');
        }

        return ApiResponseResource::success('Readiness', (new ReadinessService)->run($b));
    }

    public function rows(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, false);
        $q = ImportRow::where('batch_id', $b->id)->orderBy('id');
        $r->filled('status') && $q->where('status', $r->query('status'));
        $r->filled('sheet') && $q->where('sheet', $r->query('sheet'));
        $p = $q->paginate(min((int) $r->query('per_page', 50), 200));

        return ApiResponseResource::success('Rows', ['items' => $p->items(), 'meta' => ['total' => $p->total(), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()]]);
    }

    public function updateRow(Request $r, int $batch, int $row)
    {
        $b = $this->batch($r, $batch, true);
        if (! in_array($b->status, ['ready', 'has_errors'], true)) {
            return $this->fail('Rows can be fixed after validation and before importing.', 'invalid_state');
        }
        $in = $r->validate(['raw' => ['required', 'array', 'max:80']]);
        $rowModel = ImportRow::where('batch_id', $b->id)->findOrFail($row);
        $raw = $rowModel->raw ?? [];
        foreach ($in['raw'] as $k => $val) {
            if (array_key_exists($k, $raw) && (is_scalar($val) || $val === null)) {
                $raw[$k] = $val;
            }
        }
        $rowModel->update(['raw' => $raw]);
        (new BatchValidator)->run($b);
        $res = (new ReadinessService)->run($b->refresh());

        return ApiResponseResource::success('Row updated.', ['row' => $rowModel->refresh(), 'readiness' => $res]);
    }

    /** Changes the import options before importing (for example "skip rows with problems") and checks again. */
    public function options(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, true);
        if (! in_array($b->status, ['ready', 'has_errors'], true)) {
            return $this->fail('Options can be changed after checking and before importing.', 'invalid_state');
        }
        $in = $r->validate(\App\Modules\ProgramImport\Http\Requests\StoreBatchRequest::optionRules());
        $changes = array_intersect_key($in['options'] ?? [], array_flip(['on_duplicate', 'mode', 'link_existing', 'update_existing', 'date_format', 'default_currency',
            'default_country_code', 'compute_match_score', 'queue_ai_review_after_import', 'raise_max_awardees', 'adjust_program_balance', 'mark_checkpoints_sent']));
        $b->update(['options' => array_merge($b->options ?? [], $changes)]);
        Audit::log($b, $r->user()->id, 'options_changed', ['keys' => array_keys($changes)]);
        (new BatchValidator)->run($b);
        $res = (new ReadinessService)->run($b->refresh());

        return ApiResponseResource::success('Options updated.', (new BatchResource($b->refresh()))->resolve() + ['readiness' => $res]);
    }

    public function errors(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, false);
        $rows = ImportRow::where('batch_id', $b->id)->whereIn('status', ['invalid', 'failed'])->orderBy('id');

        return response()->streamDownload(function () use ($rows) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['sheet', 'row', 'field', 'problem', 'value'], ',', '"', '');
            foreach ($rows->cursor() as $row) {
                foreach ($row->errors ?? [] as $field => $msg) {
                    $val = $row->raw[$field] ?? '';
                    fputcsv($h, array_map(fn ($c) => Normalizer::neutralize((string) $c), [$row->sheet, $row->row_number, $field, $msg, is_scalar($val) ? $val : '']), ',', '"', '');
                }
            }
            fclose($h);
        }, "import-{$b->id}-errors.csv", ['Content-Type' => 'text/csv']);
    }

    public function commit(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, true);
        if (! $r->boolean('confirm_data_rights')) {
            return $this->fail('Confirm you have the right to import this data.', 'consent_required');
        }
        if (! in_array($b->status, ['ready', 'has_errors'], true)) {
            return $this->fail('This import cannot be committed in its current state.', 'invalid_state');
        }
        $res = (new ReadinessService)->run($b);
        if (! $res['ready']) {
            return $this->fail('Fix the blockers before importing.', 'readiness_blocked', 422, ['blockers' => $res['blockers']]);
        }
        $b->update(['consent_confirmed_at' => now(), 'options' => array_merge($b->options ?? [], ['consent_user_id' => $r->user()->id])]);
        Audit::log($b->refresh(), $r->user()->id, 'commit_confirmed', ['consent' => 'confirm_data_rights']);
        CommitBatchJob::dispatch($b->id);

        return ApiResponseResource::success('Import started.', (new BatchResource($b->refresh()))->resolve(), 202);
    }

    public function rollback(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, true);
        $blockers = (new RollbackGuard)->blockers($b);
        if ($blockers) {
            return $this->fail('This import can no longer be undone.', 'rollback_blocked', 422, ['blockers' => $blockers]);
        }
        try {
            (new Rollbacker)->run($b);
        } catch (\RuntimeException $e) {
            return $this->fail('This import can no longer be undone.', 'rollback_blocked', 422, ['blockers' => [['code' => 'user_referenced', 'message' => $e->getMessage()]]]);
        }
        Audit::log($b->refresh(), $r->user()->id, 'rolled_back');

        return ApiResponseResource::success('Import undone.', (new BatchResource($b->refresh()))->resolve());
    }

    /** A short-lived link to the uploaded file (private disk). */
    public function file(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, false);
        if (! $b->file_path) {
            return $this->fail('The file was removed.', 'file_removed', 404);
        }
        try {
            $url = BatchStager::disk()->temporaryUrl($b->file_path, now()->addMinutes(10));
        } catch (\Throwable) {
            return $this->fail('Temporary links are not available on this storage.', 'no_temporary_url');
        }

        return ApiResponseResource::success('Temporary link', ['url' => $url, 'expires_in_seconds' => 600]);
    }

    /**
     * Only on request (never automatic): queue AI review for the imported applications of the live round, through the normal
     * rate-governed queue. The round must already use an AI review method.
     */
    public function queueAiReview(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, true);
        $opts = BatchValidator::options($b);
        if (! in_array($b->status, ['completed', 'completed_with_errors'], true) || ! in_array($b->start_point, ['review', 'finalization'], true) || empty($opts['queue_ai_review_after_import'])) {
            return $this->fail('AI review can be queued after a review or finalization import that asked for it.', 'invalid_state');
        }
        $appIds = \App\Modules\ProgramImport\Models\ImportRecord::where('batch_id', $b->id)->where('record_type', \App\Models\Programs\ProgramApplication::class)->where('action', 'created')->pluck('record_id');
        $apps = \App\Models\Programs\ProgramApplication::whereIn('id', $appIds)->with('currentRound')->get()->filter(fn ($a) => $a->currentRound?->usesAi());
        if ($apps->isEmpty()) {
            return $this->fail('No imported application is in a round that uses AI-assisted or AI-only review. Change the round review method first.', 'no_ai_review_method');
        }
        $apps->each(fn ($a) => \App\Service\AiReview\AiReviewDispatcher::safely($a));
        Audit::log($b, $r->user()->id, 'ai_review_queued', ['applications' => $apps->count()]);

        return ApiResponseResource::success('AI review queued.', ['applications' => $apps->count()], 202);
    }

    /** "Mark as handled": the person has dealt with the follow-up (for example fixed the rows by hand), so it leaves their pending list. */
    public function handled(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, true);
        $stats = $b->stats ?? [];
        $stats['follow_up_handled'] = true;
        $b->update(['stats' => $stats]);
        Audit::log($b->refresh(), $r->user()->id, 'follow_up_handled');

        return ApiResponseResource::success('Marked as handled.', (new BatchResource($b->refresh()))->resolve());
    }

    /**
     * Try again after a failure or a stall. Safe by construction: rows already saved are never written twice, so only what is left runs.
     */
    public function retry(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, true);
        $stuck = $b->status === 'importing' && $b->updated_at->lt(now()->subMinutes(10));
        if (! ($b->status === 'failed' && $b->consent_confirmed_at) && ! $stuck) {
            return $this->fail('Only an import that failed or has stalled can be tried again.', 'invalid_state');
        }
        $stats = $b->stats ?? [];
        unset($stats['follow_up_handled']);
        $b->update(['status' => 'ready', 'error_summary' => null, 'stats' => $stats]);
        Audit::log($b->refresh(), $r->user()->id, 'retry');
        CommitBatchJob::dispatch($b->id);

        return ApiResponseResource::success('Trying again.', (new BatchResource($b->refresh()))->resolve(), 202);
    }

    /** The rows that could not be saved, as a spreadsheet with the same columns plus the reason, ready to fix and import again. */
    public function failedRows(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, false);
        $q = ImportRow::where('batch_id', $b->id)->whereIn('status', ['failed', 'invalid'])->orderBy('id');
        $sheet = $r->query('sheet') ?: (clone $q)->value('sheet');
        if (! $sheet) {
            return $this->fail('There are no rows to fix.', 'nothing_to_fix', 404);
        }
        $rows = $q->where('sheet', $sheet)->get();
        $headers = $rows->flatMap(fn ($x) => array_keys($x->raw ?? []))->unique()->values()->all();

        return response()->streamDownload(function () use ($rows, $headers) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, array_merge($headers, ['problem']), ',', '"', '');
            foreach ($rows as $row) {
                $problem = collect($row->errors ?? [])->implode('; ');
                fputcsv($h, array_map(fn ($c) => Normalizer::neutralize((string) $c), array_merge(array_map(fn ($k) => is_scalar($row->raw[$k] ?? null) ? $row->raw[$k] : '', $headers), [$problem])), ',', '"', '');
            }
            fclose($h);
        }, "import-{$b->id}-{$sheet}-to-fix.csv", ['Content-Type' => 'text/csv']);
    }

    public function destroy(Request $r, int $batch)
    {
        $b = $this->batch($r, $batch, true);
        if (in_array($b->status, ['importing', 'completed', 'completed_with_errors', 'rolled_back'], true)) {
            return $this->fail('A committed import cannot be cancelled. Use undo instead.', 'invalid_state');
        }
        \App\Modules\ProgramImport\Support\BatchFiles::delete($b);
        ImportRow::where('batch_id', $b->id)->delete();
        $b->update(['status' => 'cancelled']);
        Audit::log($b->refresh(), $r->user()->id, 'cancelled');

        return ApiResponseResource::success('Import cancelled.', (new BatchResource($b->refresh()))->resolve());
    }
}
