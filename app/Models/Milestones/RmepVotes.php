<?php

namespace App\Models\Milestones;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RmepVotes extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function voter()
    {
        return $this->belongsTo(User::class, 'investor_id')->select('id','fname','lname','email');
    }

    public function rmep()
    {
        return $this->belongsTo(MilestoneRmep::class, 'rmep_id');
    }
}
