<?php

namespace App\Models;

use App\Models\Programs\Program;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramEmailTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['program_id', 'event', 'subject', 'body_html'];

    // Events that support custom body templates
    public const CUSTOMISABLE_EVENTS = [
        'round.advanced',
        'application.accepted',
        'application.rejected',
        'round.not_selected',
    ];

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    /** The template the owner saved for a program + event (subject and body), or null when none is saved. */
    public static function resolve(int $programId, string $event): ?self
    {
        if (!$programId) {
            return null;
        }

        return static::where('program_id', $programId)
            ->where('event', $event)
            ->whereNotNull('body_html')
            ->where('body_html', '!=', '')
            ->first();
    }
}
