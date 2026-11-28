<?php

namespace App\Models\Milestones;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinalApprovalVote extends Model
{
    use HasFactory;

    // Investors' final-approval votes (the model allowed no fields, so every vote failed)
    protected $fillable = ['final_approval_id', 'investor_id', 'vote', 'weight', 'reason'];
}
