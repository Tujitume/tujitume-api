<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\Auth\User;
use App\Modules\ProgramImport\Models\ImportUser;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Pipeline\ImportRowException;
use App\Modules\ProgramImport\Silent\OutboundGuard;
use Illuminate\Support\Str;

/** Matches or creates the applicant account for an imported row. Existing accounts are never modified. */
class ApplicantUsers
{
    public static function resolve(CommitContext $c, ?string $email, ?string $name, ?string $phone = null): User
    {
        if ($email) {
            $u = User::where('email', $email)->first();
            if ($u) {
                if ((int) $u->user_type_id !== 1) {
                    throw new ImportRowException('This email belongs to a non-applicant account.');
                }

                return $u;
            }
        }
        $kind = $email ? 'shadow' : 'placeholder';
        $email ??= 'import+'.Str::uuid().OutboundGuard::PLACEHOLDER_DOMAIN;
        [$first, $last] = array_pad(explode(' ', trim((string) $name), 2), 2, null);
        $u = new User;
        $u->forceFill([
            'first_name' => $first ?: Str::before($email, '@'), 'last_name' => $last, 'email' => $email, 'phone' => $phone,
            'password' => null, 'email_verified_at' => null, 'user_type_id' => 1,
        ])->save();
        $c->record($u, 'created');
        ImportUser::create(['user_id' => $u->id, 'batch_id' => $c->batch->id, 'kind' => $kind]);

        return $u;
    }
}
