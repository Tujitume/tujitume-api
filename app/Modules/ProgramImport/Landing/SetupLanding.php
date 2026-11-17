<?php

namespace App\Modules\ProgramImport\Landing;

class SetupLanding extends BaseLanding
{
    public function startPoint(): string
    {
        return 'setup';
    }

    public function describe(): array
    {
        return ['program' => 'draft, hidden', 'rounds' => 'draft, dates exactly as given (never invented)', 'applications' => 'none', 'milestones' => 'none'];
    }

    public function skipped(): array
    {
        return ['Rounds are not published: finish the publish checklist yourself.'];
    }
}
