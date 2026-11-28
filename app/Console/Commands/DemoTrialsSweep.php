<?php

namespace App\Console\Commands;

use App\Service\DemoRequests\DemoRequestService;
use Illuminate\Console\Command;

/** Demo (trial) accounts: "ends soon" reminder, then deleting the ones whose 7 days are over. */
class DemoTrialsSweep extends Command
{
    protected $signature = 'demo-trials:sweep';

    protected $description = 'Remind and close 7-day demo accounts whose end has come (their data is deleted)';

    public function handle(DemoRequestService $requests): int
    {
        $result = $requests->sweep();
        $this->info("Reminded {$result['reminded']}, closed {$result['closed']}.");

        return self::SUCCESS;
    }
}
