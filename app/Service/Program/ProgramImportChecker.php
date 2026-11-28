<?php

namespace App\Service\Program;

use App\Models\Programs\Program;
use App\Models\Programs\ProgramImport;
use App\Models\ProgramIndustry;
use Illuminate\Support\Str;

/**
 * Step 3 of an import: checks what the import holds against what is already in Tujitume, without creating
 * anything. The page has already checked the file itself; this adds what only the server knows: programs
 * that already exist (same title), titles used twice in the file, the database's limits, and industries.
 *
 * The import's data (version 2) is three tables of text cells: programs, rounds, fields. In a many-programs
 * import, rounds and fields name their program in the `program` cell (the Program code).
 */
class ProgramImportChecker
{
    private const MAX_TITLE = 255;
    private const MAX_ABOUT = 500;
    private const MAX_ROUND_NAME = 100;
    private const MAX_FIELD_LABEL = 500;
    private const MAX_AMOUNT = 9_999_999_999_999.99; // decimal(15,2)

    /**
     * @return array{programs: array<int, array>, totals: array{ready: int, decide: int, problems: int}}
     *   each program: code, title, stage, rounds, fields, status (ready | decide | problems), existing_program_id, issues[]
     *   each issue: key, level (error | decision | info), extra[]
     */
    public function check(ProgramImport $import, int $ownerUserId): array
    {
        $data = $import->data ?? [];
        $programs = $data['programs'] ?? [];
        $rounds = $data['rounds'] ?? [];
        $fields = $data['fields'] ?? [];
        $coaches = $data['coaches'] ?? [];
        $many = $import->mode === 'many';

        $results = [];
        if ($import->mode === 'existing') {
            $results[] = $this->checkExisting($import, $rounds, $fields, $coaches);
        } else {
            // Every program of the organization (a team member's import is theirs, not the owner's), plus the owner's own
            $existingTitles = Program::where(fn ($q) => $q->where('user_id', $ownerUserId)->when($import->organization_id, fn ($q2) => $q2->orWhere('organization_id', $import->organization_id)))->get(['id', 'program_title'])
                ->mapWithKeys(fn ($p) => [$this->norm($p->program_title) => $p->id]);
            $industries = ProgramIndustry::pluck('name')->map(fn ($n) => $this->norm($n))->all();
            $seen = [];

            foreach ($programs as $row) {
                $cells = $row['cells'] ?? [];
                $code = trim((string) ($cells['code'] ?? ''));
                $mine = fn ($r) => ! $many || $this->norm($r['cells']['program'] ?? '') === $this->norm($code);
                $myRounds = array_values(array_filter($rounds, $mine));
                $myFields = array_values(array_filter($fields, $mine));
                $myCoaches = array_values(array_filter($coaches, $mine));
                $title = trim((string) ($cells['title'] ?? ''));
                $issues = [];

                if (Str::length($title) > self::MAX_TITLE) $issues[] = $this->issue('titleTooLong', 'error', ['max' => self::MAX_TITLE]);
                if (Str::length((string) ($cells['about'] ?? '')) > self::MAX_ABOUT) $issues[] = $this->issue('aboutTooLong', 'error', ['max' => self::MAX_ABOUT]);
                if (! in_array($this->norm($cells['industry'] ?? ''), $industries, true)) $issues[] = $this->issue('unknownIndustry', 'error', ['name' => (string) ($cells['industry'] ?? '')]);
                foreach (['total_amount', 'per_applicant'] as $key) {
                    $amount = (float) str_replace([',', ' '], '', (string) ($cells[$key] ?? '0'));
                    if ($amount > self::MAX_AMOUNT) $issues[] = $this->issue('amountTooLarge', 'error', ['column' => $key]);
                }
                $issues = array_merge($issues, $this->roundAndFieldLimits($myRounds, $myFields), $this->coachIssues($myCoaches, (int) $import->organization_id));

                $existingId = $title !== '' ? ($existingTitles[$this->norm($title)] ?? null) : null;
                if ($existingId) $issues[] = $this->issue('alreadyExists', 'decision', ['title' => $title]);
                if ($title !== '' && isset($seen[$this->norm($title)])) $issues[] = $this->issue('titleTwice', 'decision', ['code' => $seen[$this->norm($title)]]);
                if ($title !== '') $seen[$this->norm($title)] ??= $code ?: $title;

                // "Set up only": a program of a later-stage import chosen to come in as a new program, without applicants
                $setupOnly = $many && strcasecmp((string) ($cells['setup_only'] ?? ''), 'yes') === 0 && $import->stage !== 'setup';
                if ($setupOnly) $issues[] = $this->issue('setupOnly', 'info');
                $results[] = $this->result($code, $title, $setupOnly ? 'setup' : (($many && $import->stage === ProgramImport::MIXED_STAGE) ? ($cells['stage'] ?? null) : $import->stage), count($myRounds), count($myFields), $issues, $existingId)
                    + ['setup_only' => $setupOnly];
            }
        }

        return [
            'programs' => $results,
            'totals' => [
                'ready' => count(array_filter($results, fn ($r) => $r['status'] === 'ready')),
                'decide' => count(array_filter($results, fn ($r) => $r['status'] === 'decide')),
                'problems' => count(array_filter($results, fn ($r) => $r['status'] === 'problems')),
            ],
        ];
    }

    /** Adding to a program you have: the rounds it already has come first; names and labels must fit. */
    private function checkExisting(ProgramImport $import, array $rounds, array $fields, array $coaches = []): array
    {
        $program = $import->program()->withCount('rounds')->first();
        $issues = array_merge($this->roundAndFieldLimits($rounds, $fields), $this->coachIssues($coaches, (int) $import->organization_id));
        if (! $program) {
            $issues[] = $this->issue('programGone', 'error');
        } elseif (ExistingProgramState::addsFunding($import->stage) && ! in_array($import->stage, ExistingProgramState::of($program)['allowed'], true)) {
            // The program moved on since this import was started (its milestones were set up in the deal room, or
            // another import brought them in): start this one again from where it is now
            $issues[] = $this->issue('stageMovedOn', 'error', ['stage' => ExistingProgramState::of($program)['stage']]);
        } elseif ($program->rounds_count > 0 && count($rounds) > 0) {
            $issues[] = $this->issue('roundsAfterExisting', 'info', ['count' => $program->rounds_count]);
        }

        return $this->result('', (string) $program?->program_title, $import->stage, count($rounds), count($fields), $issues, $program?->id);
    }

    /**
     * Business Coaches, once per email: a team member must be on the organization's team; a service provider
     * reviews as a paid third party (added to the provider list if not on it); someone new gets an account and an
     * invitation later (they need a name); an email of another kind of account cannot be a Business Coach here.
     */
    private function coachIssues(array $coaches, int $organizationId): array
    {
        $issues = [];
        $seen = [];
        foreach ($coaches as $row) {
            $email = mb_strtolower(trim((string) ($row['cells']['email'] ?? '')));
            $kind = ($row['cells']['kind'] ?? '') === 'team' ? 'team' : 'coach';
            if ($email === '' || isset($seen["{$kind}:{$email}"])) continue;
            $seen["{$kind}:{$email}"] = true;
            $found = ImportCoachAccount::find($email, $kind, $organizationId);
            $cells = $row['cells'];
            if ($found['problem']) $issues[] = $this->issue($found['problem'], 'error', ['email' => $email]);
            elseif ($found['as'] === 'provider') {
                // A service provider reviews as a third party, and is paid for it (as on Round Config)
                $fee = (float) str_replace([',', ' '], '', (string) ($cells['fee'] ?? '0'));
                $issues[] = $fee > 0
                    ? $this->issue($found['listed'] ? 'coachProvider' : 'coachProviderAdded', 'info', ['email' => $email])
                    : $this->issue('coachProviderFee', 'error', ['email' => $email]);
            } elseif ($found['as'] === 'new' || $found['as'] === 'new_team') {
                $issues[] = trim((string) ($cells['first_name'] ?? '')) === '' || trim((string) ($cells['last_name'] ?? '')) === ''
                    ? $this->issue('coachNameNeeded', 'error', ['email' => $email])
                    : $this->issue($found['as'] === 'new_team' ? 'coachNewTeam' : 'coachNew', 'info', ['email' => $email]);
            }
        }

        return $issues;
    }

    private function roundAndFieldLimits(array $rounds, array $fields): array
    {
        $issues = [];
        foreach ($rounds as $r) {
            if (Str::length((string) ($r['cells']['name'] ?? '')) > self::MAX_ROUND_NAME) {
                $issues[] = $this->issue('roundNameTooLong', 'error', ['round' => $r['cells']['round'] ?? '', 'max' => self::MAX_ROUND_NAME]);
            }
        }
        foreach ($fields as $f) {
            if (Str::length((string) ($f['cells']['text'] ?? '')) > self::MAX_FIELD_LABEL) {
                $issues[] = $this->issue('fieldLabelTooLong', 'error', ['round' => $f['cells']['round'] ?? '', 'max' => self::MAX_FIELD_LABEL]);
            }
        }

        return $issues;
    }

    private function result(string $code, string $title, ?string $stage, int $rounds, int $fields, array $issues, ?int $existingId): array
    {
        $levels = array_column($issues, 'level');
        $status = in_array('error', $levels, true) ? 'problems' : (in_array('decision', $levels, true) ? 'decide' : 'ready');

        return compact('code', 'title', 'stage', 'rounds', 'fields', 'status', 'issues') + ['existing_program_id' => $existingId];
    }

    private function issue(string $key, string $level, array $extra = []): array
    {
        return compact('key', 'level', 'extra');
    }

    private function norm(mixed $value): string
    {
        return Str::of((string) $value)->squish()->lower()->toString();
    }
}
