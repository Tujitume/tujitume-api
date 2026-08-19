<?php

namespace App\Models\Programs\Rounds;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoundReviewer extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function programRound(){
        return $this->belongsTo(ProgramRound::class, 'round_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
