<?php

namespace App\Modules\ProgramImport\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $table = 'program_import_batches';

    protected $guarded = [];

    protected $casts = ['mapping'=>'array','form_spec'=>'array','options'=>'array','readiness'=>'array','landing_preview'=>'array','stats'=>'array','ai_usage'=>'array','error_summary'=>'array','consent_confirmed_at'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime','rolled_back_at'=>'datetime'];
}
