<?php

namespace App\Models;

use App\Models\Programs\Program;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramEmailTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['program_id', 'event', 'body_html'];

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

    /**
     * Fetch a custom body for a given program + event, or null if none saved.
     */
    public static function resolve(int $programId, string $event): ?string
    {
        if (!$programId) {
            return null;
        }

        return static::where('program_id', $programId)
            ->where('event', $event)
            ->value('body_html');
    }
}
