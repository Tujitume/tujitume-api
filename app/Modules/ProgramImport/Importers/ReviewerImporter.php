<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\Auth\User;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundReviewer;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Pipeline\ImportRowException;

/**
 * Business Coach ROSTER only. Safe by construction: no account is created, nobody is invited or notified, no fee is set,
 * no reviewer order is created and no application is assigned (assignments create orders and payments, so the owner makes
 * them through the normal screens). The coach stays `pending` until they accept through the normal flow.
 */
class ReviewerImporter extends Importer
{
    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        $round = ProgramRound::findOrFail($c->findOrFail('round', $v['round_ref']));
        $user = User::where('email', $v['email'])->first()
            ?? throw new ImportRowException('No account exists for this email. Business Coaches are never created by an import; ask them to sign up first.');
        $owner = $c->owner();
        $sameTeam = (int) $user->user_type_id === 4 && $user->organization_id && (int) $user->organization_id === $c->organizationId();
        if ((int) $user->user_type_id !== 6 && ! $sameTeam) {
            throw new ImportRowException('This account is not a Business Coach or a member of your team.');
        }
        if ((int) $user->id === (int) $owner->id) {
            throw new ImportRowException('The program owner reviews without being added to the roster.');
        }
        if ($existing = RoundReviewer::where('round_id', $round->id)->where('user_id', $user->id)->first()) {
            return ['round_reviewer', $existing->id];
        }
        $r = RoundReviewer::create([
            'round_id' => $round->id, 'user_id' => $user->id, 'reviewer_type' => $sameTeam ? 'internal' : ($v['reviewer_type'] ?? 'external'),
            'acceptance_status' => 'pending', 'max_apps_assigned' => $v['max_apps_assigned'], 'expertise_tags' => $v['expertise_tags'] ? json_encode($v['expertise_tags']) : null,
        ]);
        $c->record($r, 'created');

        return ['round_reviewer', $r->id];
    }
}
