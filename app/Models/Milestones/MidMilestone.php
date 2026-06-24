<?php

namespace App\Models\Milestones;

use App\Models\Auth\User;
use App\Models\Shared\PMAudit;
use App\Models\Shared\Vote;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MidMilestone extends Model
{
    use HasFactory;
    protected $guarded = [];

//    protected $casts = [
//        'photo_files' => 'array',
//        'video_files' => 'array',
//        'invoice_files' => 'array',
//        'work_log_files' => 'array',
//        'supplier_confirmation_files' => 'array',
//        'screenshot_files' => 'array',
//    ];

    // -----------------------------------
    // Relationships
    // -----------------------------------

    public function milestone()
    {
        return $this->belongsTo(Milestones::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Investor votes (if using separate table)
    public function votes()
    {
        return $this->hasMany(MidMilestoneVote::class);
    }

    public function votingWindow()
    {
        return $this->belongsTo(Vote::class, 'reference_id');
    }

    public function pmAudit()
    {
        return $this->hasOne(PMAudit::class, 'mid_milestone_id');
    }

    public function pmVotes()
    {
        return $this->hasMany(MidPMVote::class, 'mid_milestone_id');
    }

    public function documents(){
        return $this->hasMany(MidMilestoneDocuments::class, 'mid_milestone_id', 'id');
    }

    // For convenience: get all files as a single array
//    public function allFiles(): array
//    {
//        return array_merge(
//            $this->photo_files ?? [],
//            $this->video_files ?? [],
//            $this->invoice_files ?? [],
//            $this->work_log_files ?? [],
//            $this->supplier_confirmation_files ?? [],
//            $this->screenshot_files ?? []
//        );
//    }
}
