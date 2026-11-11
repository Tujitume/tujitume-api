<?php

namespace App\Console\Commands;

use App\Models\Programs\Ai\ApplicationDocumentExtraction;
use Illuminate\Console\Command;

class AiReviewsPurge extends Command
{
    protected $signature = 'ai-reviews:purge-extractions';
    protected $description = 'Delete stored document text older than the retention period. Findings and summaries are kept.';

    public function handle(): int
    {
        $n = ApplicationDocumentExtraction::where('expires_at', '<', now())->delete();
        $this->info("Purged {$n} extraction(s).");

        return self::SUCCESS;
    }
}
