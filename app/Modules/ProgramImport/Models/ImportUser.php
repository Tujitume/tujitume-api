<?php

namespace App\Modules\ProgramImport\Models;

use Illuminate\Database\Eloquent\Model;

class ImportUser extends Model
{
    protected $table = 'program_import_users';

    protected $guarded = [];

    protected $casts = ['claimed_at'=>'datetime'];
}
