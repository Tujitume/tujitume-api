<?php

namespace App\Modules\ProgramImport\Models;

use Illuminate\Database\Eloquent\Model;

class ImportSetting extends Model
{
    protected $table = 'program_import_settings';

    protected $guarded = [];

    protected $casts = ['ai_real_samples' => 'boolean', 'consented_at' => 'datetime'];
}
