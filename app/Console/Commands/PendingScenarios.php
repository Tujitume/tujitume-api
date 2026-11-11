<?php

namespace App\Console\Commands;

use App\Service\Pending\PendingTasks;
use Illuminate\Console\Command;

class PendingScenarios extends Command
{
    protected $signature = 'pending:scenarios';
    protected $description = 'List every Pending-drawer scenario and the user types it applies to.';

    public function handle(): int
    {
        $names = [1 => 'entrepreneur', 2 => 'investor', 3 => 'service provider', 4 => 'program org', 5 => 'capital org', 6 => 'business coach', 7 => 'reviewer'];
        $rows = [];
        foreach (PendingTasks::registry() as $key => $scenario) {
            $types = $scenario->userTypes();
            $rows[] = [$key, (new \ReflectionClass($scenario))->getShortName(), $types === null ? 'everyone' : implode(', ', array_map(fn ($t) => $names[$t] ?? $t, $types))];
        }
        $this->table(['Key', 'Class', 'Applies to'], $rows);
        $this->line(count($rows).' scenarios. To add one, create a class under app/Service/Pending/Scenarios/<Area>/ that extends Scenario.');

        return self::SUCCESS;
    }
}
