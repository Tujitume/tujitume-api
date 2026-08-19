<?php

namespace App\Models\Programs\Rounds;

use App\Models\Programs\ProgramApplication;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationRoundResponse extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function application()
    {
        return $this->belongsTo(ProgramApplication::class);
    }

    public function question()
    {
        return $this->belongsTo(RoundCustomQuestion::class, 'question_id');
    }
}
