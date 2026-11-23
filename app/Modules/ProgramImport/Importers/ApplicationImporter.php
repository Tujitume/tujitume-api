<?php

namespace App\Modules\ProgramImport\Importers;

use App\Models\ApplicationRoundHistory;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ProgramRound;
use App\Modules\ProgramImport\Models\ImportRow;
use App\Modules\ProgramImport\Pipeline\CommitContext;
use App\Modules\ProgramImport\Pipeline\ImportRowException;
use App\Modules\ProgramImport\Support\Lifecycle;
use Illuminate\Support\Facades\Schema;

class ApplicationImporter extends Importer
{
    public function import(ImportRow $row, CommitContext $c): array
    {
        $v = $row->normalized;
        $p = $c->programFor($v);
        $n = (int) ($v['round_number'] ?? 1);
        $round = ProgramRound::where('program_id', $p->id)->where('round_number', $n)->first()
            ?? throw new ImportRowException("Round $n does not exist in this program.");
        $land = $c->landing->application($n, false);
        if ($c->landing instanceof \App\Modules\ProgramImport\Landing\FundingSetupLanding && $c->hasLinkedAwardees() && ! $c->isAwardee($row->external_ref)) {
            // Round history after the award: everyone who is not an awardee stopped in the round they reached.
            $land = ['status' => 'rejected', 'round_status' => 'not_selected'];
        } elseif ($c->landing instanceof \App\Modules\ProgramImport\Landing\FundingSetupLanding && $c->isAwardee($row->external_ref)) {
            // Awardees arrive approved; the awardees sheet then moves them to awarded with the awarded amount.
            $land = ['status' => 'approved', 'round_status' => 'advanced'];
        }

        $existing = $c->find('application', $row->external_ref, $p->id);
        if (! $existing && $c->opts['link_existing'] && ! empty($v['email'])) {
            $existing = ProgramApplication::where('program_id', $p->id)->where(fn ($q) => $q->where('email', $v['email'])->orWhere('contact_person_email', $v['email']))
                ->where('startup_name', $v['startup_name'])->value('id');
            if ($existing) {
                $c->record(ProgramApplication::find($existing), 'linked');
                $c->link('application', ProgramApplication::find($existing), $row->external_ref, $p->id);
            }
        }
        if ($existing) {
            $app = ProgramApplication::findOrFail($existing);
            if ($c->opts['update_existing'] && Lifecycle::allows('application', $app->status, $land['status'] ?? $app->status)) {
                $c->updateModel($app, array_filter(['startup_name' => $v['startup_name'], 'stage' => $v['stage'], 'traction_kpis' => $v['traction_kpis']], fn ($x) => $x !== null));
            }

            return ['application', $app->id];
        }

        $user = ApplicantUsers::resolve($c, $v['email'] ?? null, $v['contact_person_name'] ?? $v['startup_name'], $v['phone'] ?? null);
        if (ProgramApplication::where('program_id', $p->id)->where('user_id', $user->id)->exists()) {
            throw new ImportRowException('This applicant already has an application in this program.');
        }
        $attrs = [
            'program_id' => $p->id, 'program_owner_id' => $p->user_id, 'user_id' => $user->id,
            'startup_name' => $v['startup_name'], 'contact_person_name' => $v['contact_person_name'] ?? $v['startup_name'],
            'contact_person_email' => $user->email, 'email' => $user->email,
            'sector' => $v['sector'], 'headquarters_location' => $v['headquarters_location'], 'total_amount_requested' => $v['total_amount_requested'],
            'stage' => $v['stage'], 'revenue_last_12_months' => $v['revenue_last_12_months'], 'traction_kpis' => $v['traction_kpis'],
            'social_impact_areas' => $v['social_impact_areas'] ?: null, 'pitch_deck_file' => $v['pitch_deck_file'], 'pitch_video' => $v['pitch_video'],
            'business_plan_file' => $v['business_plan_file'], 'match_score' => $v['match_score'], 'current_round_id' => $round->id,
        ] + $land;
        if ($attrs['revenue_last_12_months'] === null) {
            unset($attrs['revenue_last_12_months']); // keep the column default, exactly like a manual application
        }
        if (Schema::hasColumn('program_applications', 'submitted_at')) {
            $attrs['submitted_at'] = now();
        }
        foreach (['pitch_deck_file', 'business_plan_file'] as $k) {
            if (! empty($attrs[$k]) && ! preg_match('#^https?://#i', $attrs[$k])) {
                $bag = $c->attachments() ?? throw new ImportRowException('This row names a file but no attachments ZIP was uploaded.');
                $stored = app(\App\Service\File\FileUploadService::class)->saveFile($bag->uploaded($attrs[$k]), 'files/programApps/'.$p->id);
                $c->recordFile($stored);
                $attrs[$k] = $stored;
            }
        }
        // Historic decisions recorded in the file (a rejection; awards belong to the awardees sheet)
        if (($v['outcome'] ?? null) === 'rejected') {
            $attrs['status'] = 'rejected';
            $attrs['round_status'] = 'not_selected';
        }
        if (! empty($v['knockout_result'])) {
            $attrs['knockout_status'] = $v['knockout_result'];
        }
        $app = ProgramApplication::create($attrs);

        $this->history($app, $p, $n, $round);
        if (($v['outcome'] ?? null) === 'rejected') {
            ApplicationRoundHistory::where('application_id', $app->id)->where('round_id', $round->id)->update(['outcome' => 'not_selected', 'exited_at' => now(), 'outcome_notes' => 'Imported: rejected before the import']);
        }
        $this->answers($app, $p, $v['_answers'] ?? [], $c);
        $this->customAnswers($app, $p, $v['_custom'] ?? [], $c);
        if (! empty($c->opts['compute_match_score'])) {
            // The server engine replaces the imported snapshot (the same engines a manual application triggers)
            app(\App\Service\Program\RoundMatchScore::class)->recalculateApplication($app->fresh());
            $custom = app(\App\Service\Program\MatchScore\MatchScoreEngine::class)->compute($p, app(\App\Service\Program\CustomFields\CustomFieldValueService::class)->answersFor('application', $app->id));
            if ($custom['score'] !== null) {
                $app->forceFill(['match_score' => $custom['score'], 'score_breakdown' => ['source' => 'custom_fields', 'breakdown' => $custom['breakdown'], 'bonus' => $custom['bonus']]])->save();
            }
        }

        $c->record($app, 'created', [], array_intersect_key($app->getAttributes(), array_flip(['status', 'round_status', 'current_round_id', 'funding_setup_status'])));
        $c->link('application', $app, $row->external_ref, $p->id);
        $c->touchedApplications[$app->id] = true;
        $c->touchedPrograms[$p->id] = true;

        return ['application', $app->id];
    }

    /** One history row per round the applicant has been through: earlier rounds closed as advanced (synthetic), the current one open. */
    private function history(ProgramApplication $app, Program $p, int $n, ProgramRound $current): void
    {
        foreach (ProgramRound::where('program_id', $p->id)->where('round_number', '<=', $n)->orderBy('round_number')->get() as $r) {
            $isCurrent = $r->id === $current->id;
            $at = ($r->close_date ?? $r->open_date) ? ($r->close_date ?? $r->open_date)->startOfDay() : now();
            ApplicationRoundHistory::create([
                'application_id' => $app->id, 'round_id' => $r->id, 'round_number' => $r->round_number,
                'entered_at' => $at, 'submitted_at' => $at, 'exited_at' => $isCurrent ? null : $at,
                'outcome' => $isCurrent ? 'in_progress' : 'advanced', 'outcome_notes' => $isCurrent ? null : 'Imported: earlier round history is not available',
            ]);
        }
    }

    /** `custom:<field>` columns -> typed answers of the program's custom fields. Values that do not fit the field are skipped, never guessed. */
    private function customAnswers(ProgramApplication $app, Program $p, array $custom, CommitContext $c): void
    {
        $values = app(\App\Service\Program\CustomFields\CustomFieldValueService::class);
        foreach ($custom as $ref => $raw) {
            if ($raw === null || trim((string) $raw) === '') {
                continue;
            }
            $id = $c->find('field', (string) $ref, $p->id);
            $field = $id ? \App\Models\Programs\ProgramCustomField::find($id) : \App\Models\Programs\ProgramCustomField::where('program_id', $p->id)->where('key', $ref)->first();
            if (! $field || $field->audience !== 'application') {
                continue;
            }
            $o = $c->opts;
            $value = match ($field->type) {
                'number' => \App\Modules\ProgramImport\Normalizers\Normalizer::decimal($raw),
                'boolean' => \App\Modules\ProgramImport\Normalizers\Normalizer::bool($raw),
                'date' => \App\Modules\ProgramImport\Normalizers\Normalizer::date($raw, $o['date_format'] ?? null),
                'multi_select' => \App\Modules\ProgramImport\Normalizers\Normalizer::list($raw),
                'url', 'file' => \App\Modules\ProgramImport\Normalizers\Normalizer::url($raw),
                default => \App\Modules\ProgramImport\Normalizers\Normalizer::text($raw),
            };
            if ($value['error'] || $value['value'] === null) {
                continue;
            }
            $v = $value['value'];
            if ($field->type === 'select' && ! in_array((string) $v, (array) $field->options, true)) {
                continue;
            }
            if ($field->type === 'multi_select') {
                $v = array_values(array_intersect((array) $field->options, $v));
                if (! $v) {
                    continue;
                }
            }
            $stored = $values->store($p, $field, 'application', $app->id, $v);
            $c->record($stored, 'created');
        }
    }

    private function answers(ProgramApplication $app, Program $p, array $answers, CommitContext $c): void
    {
        foreach ($answers as $ref => $text) {
            if ($text === null || trim((string) $text) === '') {
                continue;
            }
            $qid = $c->find('question', (string) $ref, $p->id);
            if (! $qid) {
                continue;
            }
            $q = \App\Models\Programs\Rounds\RoundCustomQuestion::find($qid);
            $clean = \App\Modules\ProgramImport\Normalizers\Normalizer::text($text)['value'];
            $r = ApplicationRoundResponse::create(['application_id' => $app->id, 'round_id' => $q->round_id, 'question_id' => $qid, 'response' => mb_substr((string) $clean, 0, 10000)]);
            $c->record($r, 'created');
        }
    }
}
