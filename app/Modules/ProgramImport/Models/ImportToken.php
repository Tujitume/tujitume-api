<?php

namespace App\Modules\ProgramImport\Models;

use Illuminate\Database\Eloquent\Model;

class ImportToken extends Model
{
    protected $table = 'program_import_tokens';

    protected $guarded = [];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'datetime', 'scopes' => 'array'];
}
