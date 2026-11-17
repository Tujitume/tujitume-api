<?php

namespace App\Service\Pending\Scenarios\Owner;

use App\Service\Pending\Scenario;

/**
 * Things a program import left for the owner to finish by hand (rows that were not saved, an import that stalled,
 * applicants not yet invited...). The work is done by the Program Import module; without the module this is empty.
 */
class ImportFollowUps extends Scenario
{
    public function key(): string
    {
        return 'owner.import';
    }

    public function userTypes(): ?array
    {
        return [4];
    }

    public function collect(): array
    {
        $class = \App\Modules\ProgramImport\Support\FollowUps::class;
        if (! class_exists($class) || ! $this->user->organization_id) {
            return [['group' => 'manage', 'count' => 0, 'items' => []]];
        }
        $found = $class::forOrganization((int) $this->user->organization_id);

        return [['group' => 'manage', 'count' => count($found), 'items' => array_map(
            fn ($i) => $this->item($i['type'], $i['title'], $i['subtitle'], $i['link'], $i['id'], $i['due']),
            array_slice($found, 0, self::MAX_ITEMS),
        )]];
    }
}
