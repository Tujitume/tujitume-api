<?php

namespace App\Modules\ProgramImport\Pipeline;

use App\Models\Auth\User;
use App\Models\ProgramIndustry;
use App\Models\Programs\Program;
use App\Modules\ProgramImport\Mapping\MappingApplier;
use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportLink;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Normalizers\RowNormalizer;
use App\Modules\ProgramImport\Registry\ImportTypeRegistry;
use App\Modules\ProgramImport\Support\Criteria;
use App\Modules\ProgramImport\Support\Refs;

/** Dry run: normalizes every staged row, checks references, duplicates and DB-backed rules. Writes only staging tables. */
class BatchValidator
{
    public const DEFAULTS = ['on_duplicate' => 'skip', 'link_existing' => false, 'update_existing' => false, 'mode' => 'partial',
        'date_format' => null, 'default_currency' => null, 'default_country_code' => null, 'queue_ai_review_after_import' => false,
        'raise_max_awardees' => false, 'adjust_program_balance' => false, 'mark_checkpoints_sent' => false, 'round_number' => null];

    public static function options(ImportBatch $b): array
    {
        return array_merge(self::DEFAULTS, $b->options ?? []);
    }

    /** Whole validation in one go (small batches and tests). The jobs run the same three steps as separate, restartable chunks. */
    public function run(ImportBatch $batch): void
    {
        $this->begin($batch);
        foreach (ImportTypeRegistry::ORDER as $sheet) {
            $this->normalizeSheet($batch, $sheet);
        }
        $this->finish($batch);
    }

    public function begin(ImportBatch $batch): void
    {
        $batch->update(['status' => 'validating']);
    }

    /** One sheet: normalize every row (restartable: it overwrites the row's previous result). */
    public function normalizeSheet(ImportBatch $batch, string $sheet): void
    {
        $opts = self::options($batch);
        $mapping = $batch->mapping ?? ['sheets' => []];
        ImportRow::where('batch_id', $batch->id)->where('sheet', $sheet)->orderBy('id')->each(function (ImportRow $row) use ($batch, $opts, $mapping, $sheet) {
            $m = $mapping['sheets'][$sheet] ?? [];
            $applied = MappingApplier::apply($mapping, $sheet, $row->raw ?? []);
            $n = RowNormalizer::normalize($sheet, $applied['values'], $opts, $m['value_maps'] ?? [], $this->defaults($sheet, $m['defaults'] ?? [], $opts));
            $errors = $n['errors'];
            $warnings = $n['warnings'];
            $v = $n['values'];
            $this->rules($sheet, $v, $errors);
            $this->semantic($sheet, $v, $applied['answers'], $errors, $warnings, $batch);

            $key = Refs::key($sheet);
            $ref = $key ? ($v[$key] ?? null) : Refs::derived($sheet, $v);
            if ($sheet === 'program' && ! $ref && ! empty($v['program_title'])) {
                $ref = 'title:'.sha1(mb_strtolower($v['program_title']));
                $v['program_ref'] = $ref;
            }
            if ($sheet === 'rounds' && ! $ref && isset($v['round_number'])) {
                $ref = 'round-'.$v['round_number'];
                $v['round_ref'] = $ref;
            }
            if ($sheet === 'fields' && ! $ref && ! empty($v['label'])) {
                $ref = \Illuminate\Support\Str::slug($v['label'], '_');
                $v['field_ref'] = $ref;
            }
            if ($sheet === 'winners' && ! $ref) {
                $ref = ! empty($v['email']) ? 'winner:'.strtolower($v['email']).':'.sha1((string) ($v['startup_name'] ?? '')) : (! empty($v['startup_name']) ? 'winner:'.sha1(mb_strtolower($v['startup_name'])) : null);
                $ref && $v['application_ref'] = $ref;
            }
            if ($key && ! $ref && $sheet !== 'winners') {
                $errors[$key] = 'A reference is required to link rows and to avoid importing twice';
            }
            $v['_answers'] = $applied['answers'];
            $v['_custom'] = $applied['custom'];
            $row->forceFill(['normalized' => $v, 'external_ref' => $ref ? mb_substr($ref, 0, 191) : null, 'errors' => $errors ?: null, 'warnings' => $warnings ?: null, 'status' => $errors ? 'invalid' : 'valid'])->save();
        });
    }

    /** After every sheet is normalized: duplicates inside the file, cross-sheet references, already-imported rows, totals and status. */
    public function finish(ImportBatch $batch): void
    {
        $opts = self::options($batch);
        $seen = [];
        $inFile = [];
        foreach (ImportRow::where('batch_id', $batch->id)->whereNotNull('external_ref')->orderBy('id')->cursor() as $row) {
            $sheet = $row->sheet;
            $entity = Refs::entity($sheet) ?? $sheet;
            $seen[$entity][$row->external_ref] = true;
            if ($sheet === 'winners') {
                $seen['application'][$row->external_ref] = true;
            }
            if (Refs::key($sheet) === 'program_ref' || in_array($sheet, ['scores', 'submissions'], true)) {
                continue;
            }
            $dupKey = $sheet.'|'.($row->normalized['program_ref'] ?? '').'|'.$row->external_ref;
            if (isset($inFile[$dupKey])) {
                $row->forceFill(['status' => 'invalid', 'errors' => ($row->errors ?? []) + ['reference' => "Duplicate reference \"{$row->external_ref}\" (first used on row {$inFile[$dupKey]})"]])->save();
            } else {
                $inFile[$dupKey] = $row->row_number;
            }
        }

        $this->crossChecks($batch, $opts, $seen);
        $this->duplicates($batch, $opts);

        $counts = ImportRow::where('batch_id', $batch->id)->selectRaw('sheet, status, count(*) n')->groupBy('sheet', 'status')->get();
        $stats = $batch->refresh()->stats ?? [];
        $stats['validation'] = [];
        foreach ($counts as $c) {
            $stats['validation'][$c->sheet][$c->status] = (int) $c->n;
        }
        $invalid = $counts->where('status', 'invalid')->sum('n');
        $batch->update(['stats' => $stats, 'status' => $invalid ? 'has_errors' : 'ready']);
    }

    private ?\App\Modules\ProgramImport\Support\AttachmentBag $bag = null;

    private function bag(ImportBatch $batch): ?\App\Modules\ProgramImport\Support\AttachmentBag
    {
        $path = $batch->options['attachments_path'] ?? null;

        return $path ? ($this->bag ??= new \App\Modules\ProgramImport\Support\AttachmentBag($path)) : null;
    }

    private function defaults(string $sheet, array $mapped, array $opts): array
    {
        $d = $mapped;
        if (in_array($sheet, ['applications', 'scores'], true) && ! empty($opts['round_number']) && ! isset($d['round_number'])) {
            $d['round_number'] = $opts['round_number'];   // target round chosen when the batch was created
        }
        if ($sheet === 'program' && ! empty($opts['default_currency']) && ! isset($d['currency'])) {
            $d['currency'] = $opts['default_currency'];
        }

        return $d;
    }

    private function rules(string $sheet, array $v, array &$errors): void
    {
        foreach (ImportTypeRegistry::fields($sheet) as $k => $f) {
            if (! isset($v[$k]) || isset($errors[$k])) {
                continue;
            }
            foreach ($f['rules'] as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $val = $v[$k];
                if ($name === 'max' && (is_string($val) ? mb_strlen($val) > (int) $arg : $val > (float) $arg)) {
                    $errors[$k] = "{$f['label']} is too ".(is_string($val) ? "long (max $arg characters)" : "large (max $arg)");
                }
                if ($name === 'min' && is_numeric($val) && $val < (float) $arg) {
                    $errors[$k] = "{$f['label']} must be at least $arg";
                }
            }
        }
    }

    private function semantic(string $sheet, array &$v, array $answers, array &$errors, array &$warnings, ImportBatch $batch): void
    {
        if ($sheet === 'rounds' && ($v['rubric_mode'] ?? 'simple_total') === 'weighted') {
            $criteria = Criteria::parse($v['scoring_criteria'] ?? [], 'weighted');
            if ($criteria['error']) {
                $errors['scoring_criteria'] = $criteria['error'];
            }
        }
        if ($sheet === 'program' && ! isset($errors['program_industry']) && ! empty($v['program_industry'])) {
            $i = self::industry($v['program_industry']);
            if ($i) {
                $v['program_industry_id'] = $i->id;
            } else {
                $near = self::closestIndustry($v['program_industry']);
                $errors['program_industry'] = "\"{$v['program_industry']}\" is not an industry on Tujitume. ".($near ? "Did you mean \"{$near}\"? " : '').'Pick one from the list.';
            }
        }
        if ($sheet === 'program' && ($v['program_type'] ?? null) === 'single_round') {
            $v['total_rounds'] = 1;
        }
        if (in_array($sheet, ['applications', 'winners'], true) && ! empty($v['email'])) {
            $u = User::where('email', $v['email'])->first();
            if ($u && (int) $u->user_type_id !== 1) {
                $errors['email'] = 'This email belongs to a non-applicant account and cannot be used for an applicant';
            }
        }
        if ($sheet === 'applications') {
            foreach (['pitch_deck_file', 'business_plan_file'] as $k) {
                $val = $v[$k] ?? null;
                if ($val && ! preg_match('#^https?://#i', $val) && ! isset($errors[$k])) {
                    try {
                        $bag = $this->bag($batch);
                    } catch (\Throwable $e) {
                        $errors[$k] = $e->getMessage();

                        continue;
                    }
                    $problem = $bag ? $bag->problem($val) : "\"$val\" is a file name but no attachments ZIP was uploaded";
                    $problem && $errors[$k] = $problem;
                }
            }
        }
        if ($sheet === 'applications' && ! empty($v['match_score'])) {
            $warnings['match_score'] = 'Stored as an imported snapshot; it is not recalculated';
        }
        if ($sheet === 'winners' && empty($v['application_ref']) && empty($v['startup_name'])) {
            $errors['startup_name'] = 'Give a business name or an application reference to identify the awardee';
        }
        if ($sheet === 'scores') {
            $hasTotal = isset($v['total_score']);
            $hasCrit = ! empty($v['criterion']) && isset($v['criterion_score']);
            if (! $hasTotal && ! $hasCrit) {
                $errors['total_score'] = 'Give a total score, or a criterion and its score';
            }
        }
        if ($sheet === 'milestones' && ($v['fund_release_status'] ?? null) === 'released' && ! in_array($v['status'] ?? 'pending', ['approved', 'completion_approved'], true)) {
            $v['status'] = 'completion_approved';
        }
    }

    /** A referenced row must exist in this batch or already be linked for this organization. */
    private function crossChecks(ImportBatch $batch, array $opts, array $seen): void
    {
        $need = [ // sheet => [key => entity]
            'rounds' => ['program_ref' => 'program'], 'questions' => ['round_ref' => 'round'], 'fields' => ['program_ref' => 'program'], 'reviewers' => ['round_ref' => 'round'], 'site_visits' => ['checkpoint_ref' => 'checkpoint'], 'applications' => ['program_ref' => 'program'],
            'scores' => ['application_ref' => 'application'], 'winners' => ['program_ref' => 'program'],
            'milestones' => ['application_ref' => 'application'], 'milestone_suppliers' => ['milestone_ref' => 'milestone', 'supplier_ref' => 'supplier'],
            'disbursements' => ['milestone_ref' => 'milestone', 'supplier_ref' => 'supplier'], 'checkpoints' => ['application_ref' => 'application'],
            'submissions' => ['checkpoint_ref' => 'checkpoint'],
        ];
        $pid = $batch->program_id;
        foreach ($need as $sheet => $keys) {
            ImportRow::where('batch_id', $batch->id)->where('sheet', $sheet)->where('status', 'valid')->orderBy('id')->each(function (ImportRow $row) use ($keys, $seen, $batch, $pid) {
                $v = $row->normalized;
                $errors = $row->errors ?? [];
                foreach ($keys as $key => $entity) {
                    $ref = $v[$key] ?? null;
                    if (! $ref) {
                        if ($key === 'program_ref' && ($pid || count($seen['program'] ?? []) === 1)) {
                            continue; // a single program in the batch, or an existing one, is implied
                        }
                        if (in_array($key, ['supplier_ref'], true)) {
                            continue;
                        }
                        $errors[$key] = 'This reference is required';

                        continue;
                    }
                    if (isset($seen[$entity][$ref])) {
                        continue;
                    }
                    $rowPid = $this->programId($batch, $v) ?? $pid;
                    $found = ImportLink::where('organization_id', $batch->organization_id)->where('entity_type', $entity)->where(function ($q) use ($ref, $rowPid) {
                        $q->where('external_ref', $ref);
                        $rowPid ? $q->orWhere('external_ref', $rowPid.':'.$ref) : $q->orWhere('external_ref', 'like', '%:'.str_replace(['%', '_'], ['\\%', '\\_'], $ref));
                    })->exists();
                    if (! $found && $key === 'program_ref' && $pid) {
                        continue;
                    }
                    if (! $found) {
                        $errors[$key] = "Unknown $entity reference \"$ref\"";
                    }
                }
                if ($errors !== ($row->errors ?? [])) {
                    $row->forceFill(['errors' => $errors, 'status' => 'invalid'])->save();
                }
            });
        }
    }

    /** Program an already-linked row belongs to: its program_ref, else the batch's program, else the single linked program ref in the batch. */
    private function programId(ImportBatch $batch, array $v): ?int
    {
        $ref = $v['program_ref'] ?? null;
        if (! $ref) {
            $ref = ImportRow::where('batch_id', $batch->id)->where('sheet', 'program')->value('external_ref');
        }
        if ($ref && ($id = ImportLink::where('organization_id', $batch->organization_id)->where('entity_type', 'program')->where('external_ref', $ref)->value('entity_id'))) {
            return (int) $id;
        }

        return $batch->program_id;
    }

    /** "Retail & Trade", "retail and trade" and "Retail-Trade" all find "Retail and Trade". */
    public static function industryKey(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = str_replace('&', ' and ', $s);

        return preg_replace('/[^a-z0-9]+/', '', $s);
    }

    public static function industry(mixed $value): ?ProgramIndustry
    {
        if (is_numeric($value)) {
            return ProgramIndustry::find((int) $value);
        }
        $key = self::industryKey((string) $value);

        return $key === '' ? null : ProgramIndustry::all(['id', 'name'])->first(fn ($i) => self::industryKey($i->name) === $key);
    }

    public static function closestIndustry(string $value): ?string
    {
        $key = self::industryKey($value);
        $best = null;
        $bestScore = 0;
        foreach (ProgramIndustry::pluck('name') as $name) {
            similar_text($key, self::industryKey($name), $pct);
            if ($pct > $bestScore) {
                [$best, $bestScore] = [$name, $pct];
            }
        }

        return $bestScore >= 55 ? $best : null;
    }

    /** Already-imported references: skip / fail / update per on_duplicate. */
    private function duplicates(ImportBatch $batch, array $opts): void
    {
        foreach (ImportRow::where('batch_id', $batch->id)->where('status', 'valid')->whereNotNull('external_ref')->orderBy('id')->cursor() as $row) {
            $entity = Refs::entity($row->sheet);
            if (! $entity) {
                continue;
            }
            $pid = $row->sheet === 'program' ? null : $this->programId($batch, $row->normalized ?? []);
            $stored = Refs::stored($entity, $row->external_ref, $pid);
            $exists = ImportLink::where('organization_id', $batch->organization_id)->where('entity_type', $entity)->where('external_ref', $stored)->exists();
            if (! $exists) {
                continue;
            }
            match ($opts['on_duplicate']) {
                'fail' => $row->forceFill(['status' => 'invalid', 'errors' => ($row->errors ?? []) + ['reference' => 'Already imported']])->save(),
                'update' => $row->forceFill(['warnings' => ($row->warnings ?? []) + ['reference' => 'Already imported: will be updated']])->save(),
                default => $row->forceFill(['status' => 'skipped', 'warnings' => ($row->warnings ?? []) + ['reference' => 'Already imported: skipped']])->save(),
            };
        }
    }
}
