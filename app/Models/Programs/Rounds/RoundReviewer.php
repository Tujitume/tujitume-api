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

    /** The program of the round (through program_rounds), for searching and filtering by its title. */
    public function program()
    {
        return $this->hasOneThrough(\App\Models\Programs\Program::class, ProgramRound::class, 'id', 'id', 'round_id', 'program_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function reviewerOrder()
    {
        return $this->hasMany(\App\Models\ReviewerOrder::class, 'round_reviewer_id');
    }

}
