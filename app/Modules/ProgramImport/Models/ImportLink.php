<?php

namespace App\Modules\ProgramImport\Models;

use Illuminate\Database\Eloquent\Model;

class ImportLink extends Model
{
    protected $table = 'program_import_links';

    protected $guarded = [];
    public $timestamps = false;

    protected $casts = [];
}
