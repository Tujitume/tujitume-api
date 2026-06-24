<?php

namespace App\Models;

use App\Models\Grants\Grant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GrantEmailTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['grant_id', 'event', 'body_html'];

    // Events that support custom body templates
    public const CUSTOMISABLE_EVENTS = [
        'round.advanced',
        'application.accepted',
        'application.rejected',
        'round.not_selected',
    ];

    public function grant()
    {
        return $this->belongsTo(Grant::class);
    }

    /**
     * Fetch a custom body for a given grant + event, or null if none saved.
     */
    public static function resolve(int $grantId, string $event): ?string
    {
        if (!$grantId) {
            return null;
        }

        return static::where('grant_id', $grantId)
            ->where('event', $event)
            ->value('body_html');
    }
}
