<?php

namespace App\Models\Programs\Rounds;

use App\Models\Programs\ProgramApplication;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationRoundResponse extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = ['file_paths' => 'array'];

    /** Every file of this answer (older answers only have `file_path`). */
    public function allFilePaths(): array
    {
        return $this->file_paths ?: ($this->file_path ? [$this->file_path] : []);
    }

    public function application()
    {
        return $this->belongsTo(ProgramApplication::class);
    }

    public function question()
    {
        return $this->belongsTo(RoundCustomQuestion::class, 'question_id');
    }
}
