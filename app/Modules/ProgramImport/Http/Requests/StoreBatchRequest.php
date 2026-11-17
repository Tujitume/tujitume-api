<?php

namespace App\Modules\ProgramImport\Http\Requests;

use App\Modules\ProgramImport\Policies\ImportAccess;
use App\Modules\ProgramImport\Registry\ImportTypeRegistry;
use App\Modules\ProgramImport\Registry\StartPointCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && ImportAccess::canWrite($this->user());
    }

    public function rules(): array
    {
        return [
            'start_point' => ['nullable', Rule::in(StartPointCatalog::POINTS)],
            'type' => ['nullable', Rule::in(array_merge(ImportTypeRegistry::ORDER, ['form']))],
            'source' => ['required', Rule::in(['file', 'google_form_url', 'google_api', 'text'])],
            'file' => ['required_if:source,file', 'file', 'max:'.config('program_imports.limits.max_file_kb')],
            'url' => ['required_if:source,google_form_url,google_api', 'nullable', 'string', 'max:500'],
            'round_id' => ['nullable', 'integer'],
            'round_number' => ['nullable', 'integer', 'min:1', 'max:10'],
            'text' => ['required_if:source,text', 'nullable', 'string', 'max:200000'],
            'attachments' => ['nullable', 'file', 'max:'.(config('program_imports.limits.max_file_kb') * 5)],
            'program_id' => ['nullable', 'integer'],
            'options' => ['nullable', 'array'],
            'options.on_duplicate' => ['nullable', Rule::in(['skip', 'update', 'fail'])],
            'options.mode' => ['nullable', Rule::in(['partial', 'all_or_nothing'])],
            'options.link_existing' => ['nullable', 'boolean'],
            'options.update_existing' => ['nullable', 'boolean'],
            'options.date_format' => ['nullable', 'string', 'max:20'],
            'options.default_currency' => ['nullable', Rule::in(['KES', 'USD'])],
            'options.default_country_code' => ['nullable', 'regex:/^\d{1,4}$/'],
            'options.compute_match_score' => ['nullable', 'boolean'],
            'options.queue_ai_review_after_import' => ['nullable', 'boolean'],
            'options.raise_max_awardees' => ['nullable', 'boolean'],
            'options.adjust_program_balance' => ['nullable', 'boolean'],
            'options.mark_checkpoints_sent' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function ($v) {
            if (! $this->start_point && ! $this->type && ! in_array($this->source, ['google_form_url', 'google_api'], true)) {
                $v->errors()->add('start_point', 'Choose a start point or a single data type.');
            }
        }];
    }
}
