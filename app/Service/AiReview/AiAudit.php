<?php

namespace App\Service\AiReview;

use Illuminate\Support\Facades\DB;

class AiAudit
{
    public static function record(string $action, ?int $roundId = null, ?int $applicationId = null, array $meta = []): void
    {
        DB::table('ai_review_audit_logs')->insert([
            'user_id' => auth()->id(), 'round_id' => $roundId, 'application_id' => $applicationId, 'action' => $action,
            'meta' => $meta ? json_encode($meta) : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
