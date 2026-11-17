<?php

namespace App\Modules\ProgramImport\Models;

use Illuminate\Database\Eloquent\Model;

class ImportRecord extends Model
{
    protected $table = 'program_import_records';

    protected $guarded = [];

    protected $casts = ['before'=>'array'];
}
