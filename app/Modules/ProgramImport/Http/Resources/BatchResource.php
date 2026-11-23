<?php

namespace App\Modules\ProgramImport\Http\Resources;

use App\Modules\ProgramImport\Models\ImportRow;
use Illuminate\Http\Resources\Json\JsonResource;

class BatchResource extends JsonResource
{
    public function toArray($request): array
    {
        $b = $this->resource;
        $rows = ImportRow::where('batch_id', $b->id)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');

        return [
            'id' => $b->id, 'program_id' => $b->program_id,
            'program_title' => ($pid = $b->program_id ?? ($b->stats['commit']['program_id'] ?? null)) ? \App\Models\Programs\Program::whereKey($pid)->value('program_title') : null,
            'started_by' => $b->user_id ? \App\Models\Auth\User::whereKey($b->user_id)->get(['first_name', 'last_name'])->map(fn ($u) => trim($u->first_name.' '.$u->last_name))->first() : null, 'start_point' => $b->start_point, 'type' => $b->type, 'source' => $b->source,
            'status' => $b->status, 'original_filename' => $b->original_filename, 'options' => $b->options, 'mapping' => $b->mapping,
            'form_spec' => $b->form_spec, 'readiness' => $b->readiness, 'landing_preview' => $b->landing_preview, 'stats' => $b->stats,
            'rows' => ['total' => (int) $rows->sum(), 'by_status' => $rows],
            'ai_usage' => $b->ai_usage, 'error_summary' => $b->error_summary, 'consent_confirmed_at' => $b->consent_confirmed_at,
            'started_at' => $b->started_at, 'completed_at' => $b->completed_at, 'rolled_back_at' => $b->rolled_back_at, 'created_at' => $b->created_at, 'updated_at' => $b->updated_at,
        ];
    }
}
