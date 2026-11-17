<?php

namespace App\Modules\ProgramImport\Policies;

use App\Models\Auth\User;
use App\Modules\ProgramImport\Models\ImportBatch;

class ImportBatchPolicy
{
    public function view(User $user, ImportBatch $batch): bool
    {
        return (int) $batch->organization_id === ImportAccess::organizationId($user) && ImportAccess::canRead($user);
    }

    public function update(User $user, ImportBatch $batch): bool
    {
        return (int) $batch->organization_id === ImportAccess::organizationId($user) && ImportAccess::canWrite($user);
    }
}
