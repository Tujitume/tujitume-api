<?php

namespace App\Models\Grants\Rounds;

use App\Models\Grants\GrantApplication;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationRoundResponse extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function application()
    {
        return $this->belongsTo(GrantApplication::class);
    }

    public function question()
    {
        return $this->belongsTo(RoundCustomQuestion::class, 'question_id');
    }
}
