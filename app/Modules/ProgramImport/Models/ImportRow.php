<?php

namespace App\Modules\ProgramImport\Models;

use Illuminate\Database\Eloquent\Model;

class ImportRow extends Model
{
    protected $table = 'program_import_rows';

    protected $guarded = [];

    protected $casts = ['raw'=>'array','normalized'=>'array','errors'=>'array','warnings'=>'array'];
}
