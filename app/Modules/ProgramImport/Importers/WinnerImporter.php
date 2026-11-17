<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\ApplicationRoundHistory;
use App\Models\Programs\ProgramApplication;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Pipeline\ImportRowException;
use App\Modules\ProgramImport\Support\Lifecycle;

/** Awardees: lands an application (existing, linked or new) in the awarded state with the same values finalize/advance set. */
class WinnerImporter extends Importer
{
    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        $p = $c->programFor($v);
        $final = $c->finalRound($p);
        $land = $c->landing->application((int) $final->round_number, false);

        $id = $c->find('application', $row->external_ref, $p->id);
        if (! $id && $c->opts['link_existing'] && ! empty($v['email'])) {
            $id = ProgramApplication::where('program_id', $p->id)->where(fn ($q) => $q->where('email', $v['email'])->orWhere('contact_person_email', $v['email']))
                ->when(! empty($v['startup_name']), fn ($q) => $q->where('startup_name', $v['startup_name']))->value('id');
            if ($id) {
                $c->record(ProgramApplication::find($id), 'linked');
                $c->link('application', ProgramApplication::find($id), $row->external_ref, $p->id);
            }
        }

        $this->guardCapacity($c, $p, $id);

        if ($id) {
            $app = ProgramApplication::findOrFail($id);
            if (in_array($app->status, ['rejected', 'withdrawn'], true)) {
                throw new ImportRowException("This application is {$app->status}; it cannot be moved to awarded by an import.");
            }
            if (Lifecycle::allows('application', $app->status, 'awarded') && $app->status !== 'awarded') {
                $c->updateModel($app, $this->values($v, $land, $final, $app->total_amount_requested));
                $this->history($app, $final);
            }
        } else {
            foreach (['sector' => 'Sector', 'headquarters_location' => 'Headquarters location'] as $k => $label) {
                if (empty($v[$k])) {
                    throw new ImportRowException("$label is required for awardees that are not already applications (or set a default).");
                }
            }
            if (empty($v['startup_name'])) {
                throw new ImportRowException('A business name is required.');
            }
            $user = ApplicantUsers::resolve($c, $v['email'] ?? null, $v['startup_name']);
            if (ProgramApplication::where('program_id', $p->id)->where('user_id', $user->id)->exists()) {
                throw new ImportRowException('This applicant already has an application in this program. Turn on "link existing" to use it.');
            }
            $app = ProgramApplication::create([
                'program_id' => $p->id, 'program_owner_id' => $p->user_id, 'user_id' => $user->id, 'startup_name' => $v['startup_name'],
                'contact_person_name' => $v['startup_name'], 'contact_person_email' => $user->email, 'email' => $user->email,
                'sector' => $v['sector'], 'headquarters_location' => $v['headquarters_location'],
            ] + $this->values($v, $land, $final, $v['awarded_amount']));
            $this->history($app, $final);
            $c->record($app, 'created', [], array_intersect_key($app->getAttributes(), array_flip(['status', 'round_status', 'current_round_id', 'funding_setup_status'])));
            $c->link('application', $app, $row->external_ref, $p->id);
        }
        $c->touchedApplications[$app->id] = true;
        $c->touchedPrograms[$p->id] = true;

        return ['application', $app->id];
    }

    /** Mirrors advanceManual/finalize: status awarded, round_status advanced, awarded_amount; plus funding-setup values. */
    private function values(array $v, array $land, $final, $requested): array
    {
        return $land + [
            'awarded_amount' => $v['awarded_amount'], 'awarded_at' => ($v['awarded_at'] ?? now()->toDateString()).' 00:00:00',
            'planning_mode' => $v['planning_mode'] ?? 'locked', 'current_round_id' => $final->id,
            'total_amount_requested' => $requested ?: $v['awarded_amount'],
        ];
    }

    private function history(ProgramApplication $app, $final): void
    {
        $at = ($final->close_date ?? now())->copy()->startOfDay();
        ApplicationRoundHistory::updateOrCreate(
            ['application_id' => $app->id, 'round_id' => $final->id],
            ['round_number' => $final->round_number, 'entered_at' => $at, 'submitted_at' => $at, 'exited_at' => $at, 'outcome' => 'awarded', 'outcome_notes' => 'Imported awardee'],
        );
    }

    private function guardCapacity(CommitContext $c, $p, ?int $existingId): void
    {
        $max = (int) ($p->max_awardees ?? floor($p->total_program_amount / max($p->funding_per_business, 1)));
        $awarded = ProgramApplication::where('program_id', $p->id)->where('status', 'awarded')->when($existingId, fn ($q) => $q->where('id', '!=', $existingId))->count();
        if ($awarded + 1 > $max) {
            if (! $c->opts['raise_max_awardees']) {
                throw new ImportRowException("Awardee limit of $max reached. Turn on \"raise max awardees\" to continue.");
            }
            $c->updateModel($p, ['max_awardees' => $awarded + 1]);
        }
    }
}
