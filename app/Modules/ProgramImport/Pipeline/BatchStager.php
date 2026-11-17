<?php

namespace App\Modules\ProgramImport\Pipeline;

use App\Modules\ProgramImport\Mapping\HeuristicMapper;
use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Parsing\ImportLimitException;
use App\Modules\ProgramImport\Parsing\SpreadsheetReader;
use App\Modules\ProgramImport\Registry\ImportTypeRegistry;
use App\Modules\ProgramImport\Registry\StartPointCatalog;
use App\Modules\ProgramImport\Support\Refs;
use Illuminate\Support\Facades\Storage;

/** Parse (stream the file into staged rows) and analyze (propose a mapping). Nothing touches real tables. */
class BatchStager
{
    public function __construct(private SpreadsheetReader $reader = new SpreadsheetReader, private HeuristicMapper $mapper = new HeuristicMapper) {}

    public static function disk()
    {
        return Storage::disk(config('program_imports.disk'));
    }

    /** sheet name in the file => registry type (bundles) or null when it is not a known sheet. */
    public static function sheetType(string $name): ?string
    {
        $n = str_replace(' ', '_', HeuristicMapper::norm($name));
        foreach (ImportTypeRegistry::ORDER as $t) {
            if ($n === $t || $n === rtrim($t, 's') || $n === str_replace('_', '', $t)) {
                return $t;
            }
        }

        return ['application' => 'applications', 'round' => 'rounds', 'winner' => 'winners', 'awardees' => 'winners', 'milestone' => 'milestones', 'score' => 'scores'][$n] ?? null;
    }

    public function parse(ImportBatch $batch): void
    {
        $batch->update(['status' => 'parsing', 'started_at' => now(), 'error_summary' => null]);
        ImportRow::where('batch_id', $batch->id)->delete();
        $tmp = tempnam(sys_get_temp_dir(), 'pi');
        try {
            $in = self::disk()->readStream($batch->file_path);
            file_put_contents($tmp, $in);
            if (is_resource($in)) {
                fclose($in);
            }
            $ext = strtolower(pathinfo($batch->file_path, PATHINFO_EXTENSION));
            $limits = config('program_imports.limits');
            $sheets = [];
            $names = $this->reader->sheetNames($tmp, $ext);
            $bundle = ! ImportTypeRegistry::has($batch->type); // a start point name = multi-sheet bundle
            $plan = [];
            foreach ($names as $name) {
                $type = self::sheetType($name);
                if (! $bundle) {
                    $type = ($type === $batch->type || count($names) === 1) ? $batch->type : null;
                }
                if ($type) {
                    $plan[$name] = $type;
                } else {
                    $sheets['_ignored'][] = $name;
                }
            }
            $total = 0;
            foreach ($plan as $name => $type) {
                $headers = [];
                $n = 0;
                $buffer = [];
                foreach ($this->reader->rows($tmp, $ext, $name, $limits['max_rows'], $limits['max_columns']) as $r) {
                    $headers = $headers ?: array_keys($r['cells']);
                    $n++;
                    if (++$total > $limits['max_rows']) {
                        throw new ImportLimitException('The file has more than '.$limits['max_rows'].' rows in total.');
                    }
                    $buffer[] = ['batch_id' => $batch->id, 'sheet' => $type, 'row_number' => $r['row'], 'raw' => json_encode($r['cells'], JSON_INVALID_UTF8_SUBSTITUTE), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()];
                    if (count($buffer) >= 200) {
                        ImportRow::insert($buffer);
                        $buffer = [];
                    }
                }
                $buffer && ImportRow::insert($buffer);
                $sheets[$type] = ['sheet_name' => $name, 'headers' => $headers, 'rows' => $n];
            }
            if (! array_diff(array_keys($sheets), ['_ignored'])) {
                throw new ImportLimitException('No usable data was found in the file. Use one of the templates.');
            }
            $batch->update(['status' => 'needs_mapping', 'stats' => array_merge($batch->stats ?? [], ['sheets' => $sheets, 'rows_total' => $total])]);
        } catch (ImportLimitException|\Throwable $e) {
            $batch->update(['status' => 'failed', 'error_summary' => ['message' => $e instanceof ImportLimitException ? $e->getMessage() : 'The file could not be read. Check it is a valid CSV or XLSX.']]);
            if (! $e instanceof ImportLimitException) {
                report($e);
            }
        } finally {
            @unlink($tmp);
        }
    }

    /** Heuristic mapping for every staged sheet (AI refinement is layered on top by AiMapper when enabled). */
    public function analyze(ImportBatch $batch, array $questions = [], array $customFields = []): void
    {
        $mapping = ['sheets' => []];
        foreach ($batch->stats['sheets'] ?? [] as $type => $info) {
            if ($type === '_ignored' || ! ImportTypeRegistry::has($type)) {
                continue;
            }
            $h = $this->mapper->map($type, $info['headers'], $type === 'applications' ? $questions : [], $type === 'applications' ? $customFields : []);
            $mapping['sheets'][$type] = $h + ['value_maps' => [], 'defaults' => []];
        }
        $batch->update(['mapping' => $mapping, 'status' => 'mapped']);
    }

    /** field ref (or slug of the label) => label, from the staged fields sheet and the target program's existing fields */
    public static function stagedFields(ImportBatch $batch): array
    {
        $out = [];
        if ($batch->program_id) {
            foreach (\App\Models\Programs\ProgramCustomField::where('program_id', $batch->program_id)->where('audience', 'application')->where('is_archived', false)->get() as $f) {
                $out[$f->key] = $f->label;
            }
        }
        foreach (ImportRow::where('batch_id', $batch->id)->where('sheet', 'fields')->get() as $r) {
            $raw = array_change_key_case($r->raw ?? [], CASE_LOWER);
            $label = $raw['label'] ?? $raw['field'] ?? null;
            $audience = strtolower((string) ($raw['audience'] ?? 'application'));
            if ($label && $audience === 'application') {
                $out[(string) ($raw['field_ref'] ?? $raw['key'] ?? \Illuminate\Support\Str::slug((string) $label, '_'))] = (string) $label;
            }
        }

        return $out;
    }

    /** question_ref => text from the staged questions sheet (raw), for header-to-question matching. */
    public static function stagedQuestions(ImportBatch $batch): array
    {
        $out = [];
        foreach (ImportRow::where('batch_id', $batch->id)->where('sheet', 'questions')->get() as $r) {
            $raw = array_change_key_case($r->raw ?? [], CASE_LOWER);
            $ref = $raw['question_ref'] ?? $raw['id'] ?? $raw['question id'] ?? null;
            $text = $raw['question_text'] ?? $raw['question'] ?? $raw['label'] ?? $raw['text'] ?? null;
            if ($ref && $text) {
                $out[(string) $ref] = (string) $text;
            }
        }

        return $out;
    }
}
