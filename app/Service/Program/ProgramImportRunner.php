<?php

namespace App\Service\Program;

use App\Models\ApplicationRoundHistory;
use App\Models\Auth\User;
use App\Models\Auth\UserSetting;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramImport;
use App\Models\Programs\ProgramWallet;
use App\Models\Programs\Rounds\ApplicationRoundResponse;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundReviewer;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Models\ReviewerOrder;
use App\Models\Organizations\OrganizationProvider;
use App\Models\Programs\Rounds\RoundCustomQuestion;
use App\Models\ProgramIndustry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Creates one program of an import, the way the app creates it by hand (ProgramController@store,
 * ProgramRoundController@store, RoundQuestionController@store), in one transaction: the program (a hidden draft:
 * nothing is published or sent), its wallet, Round 1 (which every program has), the later rounds, and every
 * application field through RoundMatchScore::prepareQuestionForSave, the app's own field checks.
 * "Adding to a program you have" adds the rounds after the ones the program already has.
 *
 * A plan item (built by the import page from the checked import):
 *   { code, program_id?, program?: { program_title, program_description, industry, total_program_amount,
 *     funding_per_business, disbursement_type, application_start_date, application_deadline, program_type,
 *     total_rounds, use_match_score, match_score_limit },
 *     rounds: [{ round_number, round_name, open_date, close_date, review_period_end, announcement_date,
 *       review_method, advancement_mode, score_threshold, max_advancing,
 *       rubric_mode?, assignment_type?, assignment_method?, min_reviewers_required?,
 *       scoring_criteria?: [{ name, description, weight, scoreRange }],
 *       questions: [{ question_text, question_type, options, is_required, knockout_fail_value,
 *         use_in_match_score, match_points, match_answers }],
 *       applications?: [{ email, first_name, last_name, phone, business_name, amount_requested, submitted_on,
 *         answers: [{ question (the field's place in the round, from 0), response (as the form stores it) }] }] }] }
 * Only these keys are read; anything else in the plan is ignored.
 *
 * Applications (Start at review and later) come in as submitted: the applicant's account is made quietly when
 * there is none (no password, no email or notification sent; they claim it with "Forgot password" on the email),
 * one application per person per program, a history row per round they took part in, and their answers.
 * Nothing is sent to anyone and no AI review is started: the program is still a hidden draft.
 */
class ProgramImportRunner
{
    private const QUESTION_KEYS = ['question_text', 'question_type', 'options', 'is_required', 'knockout_fail_value', 'use_in_match_score', 'match_points', 'match_answers'];
    private const REVIEW_METHODS = ['manual', 'ai_assisted', 'ai_only'];
    private const ADVANCEMENT = ['manual', 'score_threshold', 'fixed_quota'];
    private const RUBRIC_MODES = ['weighted', 'simple_total', 'pass_fail'];
    private const ASSIGNMENT_TYPES = ['owner_only', 'internal', 'external', 'both'];
    private const ASSIGNMENT_METHODS = ['manual', 'round_robin', 'load_balanced'];
    private const SCORE_RANGES = ['0-5', '0-10', '0-100'];
    private const APPLICANT_TYPE = 1; // business owner

    /** The applications the last run() created or added rounds to (kept for "Let applicants know"). */
    public array $applicationIds = [];

    /** The program being imported comes in "set up only" (a new program, without applicants, coaches or scores) */
    private bool $setupOnly = false;

    /** The Business Coach places (round_reviewers) the last run() made (kept for "Invite Business Coaches"). */
    public array $reviewerIds = [];

    /** email → application id, of the program being imported (for its scores) */
    private array $applicationByEmail = [];

    /** round_reviewer id → whether that coach was already paid outside Tujitume (finished rounds) */
    private array $paidCoaches = [];

    /** The time zone the file's times are read in: the import owner's own */
    private string $zone = 'Africa/Nairobi';

    /** Applications awarded in the program being created (Funding setup) */
    private int $awardedCount = 0;

    /** Start at disbursement: the milestone suppliers made (paid direct to a supplier), and the suppliers new to the owner's list, for "Tell suppliers" */
    public array $supplierLinkIds = [];
    public array $newSupplierIds = [];

    /** Start at monitoring & evaluation: the scheduled site visits made (kept for "Tell site visit officers") */
    public array $siteVisitIds = [];

    public function __construct(private RoundMatchScore $matchScore, private KnockoutEvaluator $knockout, private RubricCalculator $rubric, private ScoreAverager $averager)
    {
    }

    /** @return int the program's id */
    public function run(ProgramImport $import, array $item): int
    {
        // Times in the file are the owner's, read in their own time zone (as Create Program reads them)
        $this->zone = \App\Support\ProgramSchedule::forUser($import->user);
        $this->applicationIds = [];
        $this->setupOnly = ! empty($item['setup_only']);
        $this->reviewerIds = [];
        $this->applicationByEmail = [];
        $this->paidCoaches = [];
        $this->awardedCount = 0;
        $this->supplierLinkIds = [];
        $this->newSupplierIds = [];
        $this->siteVisitIds = [];

        return DB::transaction(function () use ($import, $item) {
            if ($import->mode === 'existing') {
                // One the person who started the import runs: theirs, or their organization's (as the page checked)
                $program = Program::lockForUpdate()->find($item['program_id'] ?? $import->program_id);
                if (! $program || ! (new ProgramAccess())->ownsProgram($import->user, $program)) throw new RuntimeException('The program this import adds to is no longer in your account.');
                // Bringing it on from funding setup (disbursement, monitoring): its own awardees' milestones, with their
                // budget and suppliers, the payments already made and the checkpoints, as for a program brought in whole
                if (ExistingProgramState::addsFunding($import->stage)) {
                    $this->useAwardeesOf($program, $item['milestones'] ?? []);
                    if (! empty($item['milestones'])) $this->addMilestones($import, $program, $item['milestones']);
                    if (! empty($item['checkpoints'])) $this->addCheckpoints($import, $program, $item['checkpoints']);

                    return $program->id;
                }
                // Adding to a program you have: new rounds and their fields, after the program's own rounds. Applicants,
                // coaches and scores cannot be placed in rounds the program already has, so none are brought in here
                $rounds = array_map(fn ($r) => array_diff_key($r, array_flip(['applications', 'coaches', 'scores'])), $item['rounds'] ?? []);
                $this->addRounds($import, $program, $rounds, (int) $program->rounds()->max('round_number'));

                return $program->id;
            }

            $program = $this->createProgram($import, $item['program'] ?? []);
            $created = $this->addRounds($import, $program, $item['rounds'] ?? [], 0);
            $this->addApplications($program, $created);
            // Funding setup: the last round is decided and someone was awarded, so the program is awarded, as finalizing
            // its last round leaves it (it stays hidden, like every imported program)
            if ($this->awardedCount > 0) {
                // The app awards at the program's last round: the file must hold all of its rounds
                if ((int) $program->total_rounds > count($created)) throw new RuntimeException("The program has {$program->total_rounds} rounds but the file has ".count($created).'; awards are made in the last round. Add the missing rounds, or change the number of rounds.');
                $program->forceFill(['status' => 'awarded'])->save();
            }
            // Start at disbursement: each awardee's funding is set up, as activating the plan in the deal room leaves it
            if (! empty($item['milestones'])) $this->addMilestones($import, $program, $item['milestones']);
            $this->addCoaches($import, $program, $created);
            $this->addScores($program, $created);
            // Start at monitoring & evaluation: each awardee's checkpoints, with the reports received and site visits
            if (! empty($item['checkpoints'])) $this->addCheckpoints($import, $program, $item['checkpoints']);

            return $program->id;
        });
    }

    /**
     * The program's awardees, by the email the file names them with, so milestones and checkpoints find them. One that
     * already has an active funding plan cannot be given milestones again (the deal room changes those).
     */
    private function useAwardeesOf(Program $program, array $milestones): void
    {
        $withMilestones = collect($milestones)->map(fn ($m) => mb_strtolower(trim((string) ($m['awardee'] ?? ''))))->filter()->flip();
        ProgramApplication::where('program_id', $program->id)->whereIn('status', ['awarded', 'in_monitoring', 'completed'])->with('user:id,email')->get()
            ->each(function (ProgramApplication $a) use ($withMilestones) {
                $email = ExistingProgramState::emailOf($a);
                if ($email === '') return;
                if ($withMilestones->has($email) && \App\Models\Programs\ProgramMilestone::where('app_id', $a->id)->where('is_template', false)->exists()) {
                    throw new RuntimeException("{$email} already has milestones in this program. Change them in the deal room, or leave their rows out.");
                }
                // A draft plan the deal room has not activated (template milestones) is replaced by the file's
                if ($withMilestones->has($email)) \App\Models\Programs\ProgramMilestone::where('app_id', $a->id)->where('is_template', true)->get()->each->delete();
                $this->applicationByEmail[$email] = $a->id;
            });
    }

    private function createProgram(ProgramImport $import, array $p): Program
    {
        $industry = ProgramIndustry::whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) ($p['industry'] ?? '')))])->first();
        if (! $industry) throw new RuntimeException('The industry "'.($p['industry'] ?? '').'" is not one Tujitume knows.');
        $title = trim((string) ($p['program_title'] ?? ''));
        if ($title === '') throw new RuntimeException('The program has no title.');

        $total = (float) ($p['total_program_amount'] ?? 0);
        $per = (float) ($p['funding_per_business'] ?? 0);
        if ($total <= 0 || $per <= 0 || $per > $total) throw new RuntimeException('The amounts are not valid: funding per applicant must be more than 0 and not more than the total.');
        $multi = ($p['program_type'] ?? '') === 'multi_round';
        $useMatch = (bool) ($p['use_match_score'] ?? true);

        $program = Program::create([
            'user_id' => $import->user_id,
            'organization_id' => $import->organization_id,
            'program_industry_id' => $industry->id,
            'program_title' => mb_substr($title, 0, 255),
            'program_description' => isset($p['program_description']) ? mb_substr((string) $p['program_description'], 0, 500) : null,
            'total_program_amount' => $total,
            'available_amount' => $total,
            'funding_per_business' => $per,
            'max_awardees' => (int) floor($total / max($per, 1)),
            'disbursement_type' => in_array($p['disbursement_type'] ?? null, ['supplier', 'beneficiary', 'hybrid'], true) ? $p['disbursement_type'] : 'supplier',
            'application_start_date' => $p['application_start_date'] ?? null,
            'application_deadline' => $p['application_deadline'] ?? null,
            // With a time, the exact moments applications open and close, read in the owner's time zone and kept in
            // UTC with that zone, as Create Program keeps them (the countdowns use them); without, the whole days
            'timezone' => $this->zone,
            ...array_filter([
                'application_opens_at' => ! empty($p['application_opens_at']) ? \App\Support\ProgramSchedule::parse($p['application_opens_at'], $this->zone) : null,
                'application_closes_at' => ! empty($p['application_closes_at']) ? \App\Support\ProgramSchedule::parse($p['application_closes_at'], $this->zone) : null,
            ]),
            'program_type' => $multi ? 'multi_round' : 'single_round',
            'total_rounds' => $multi ? max(2, min(10, (int) ($p['total_rounds'] ?? 2))) : 1,
            'use_match_score' => $useMatch,
            'match_score_limit' => $useMatch && isset($p['match_score_limit']) ? max(1, min(100, (int) $p['match_score_limit'])) : null,
            'currency' => 'KES',
            // Imported programs start hidden and in draft: nothing is published or sent while importing
            'status' => 'draft',
            'visible' => false,
        ]);

        ProgramWallet::create([
            'program_id' => $program->id, 'currency' => $program->currency ?: config('app.currency', 'KES'),
            'total_deposited' => 0, 'total_disbursed' => 0, 'total_reserved' => 0, 'balance' => 0, 'status' => 'inactive',
        ]);

        return $program;
    }

    /**
     * Rounds in order, numbered after `$after` (0 for a new program), each with its application fields.
     * @return array<int, array{round: ProgramRound, questions: RoundCustomQuestion[], applications: array}> in round order
     */
    private function addRounds(ProgramImport $import, Program $program, array $rounds, int $after): array
    {
        usort($rounds, fn ($a, $b) => (int) ($a['round_number'] ?? 0) <=> (int) ($b['round_number'] ?? 0));
        $created = [];
        foreach (array_values($rounds) as $i => $r) {
            $number = $after + $i + 1;
            $round = ProgramRound::create($this->roundColumns($import, $program, $r, $number));
            $questions = [];
            foreach (array_values($r['questions'] ?? []) as $q => $question) {
                $questions[] = $this->addQuestion($round, $question, $q + 1);
            }
            $this->matchScore->recalculateRound($round);
            // A "set up only" program brings its rounds and fields only
            $later = fn ($key) => $this->setupOnly ? [] : array_values($r[$key] ?? []);
            $created[] = ['round' => $round, 'questions' => $questions, 'applications' => $later('applications'), 'coaches' => $later('coaches'), 'scores' => $later('scores')];
        }
        if ($program->total_rounds < $after + count($rounds)) {
            $program->update(['total_rounds' => $after + count($rounds), 'program_type' => $after + count($rounds) > 1 ? 'multi_round' : $program->program_type]);
        }

        return $created;
    }

    /**
     * The applicants of the imported rounds: one application per person (by email) in this program. Someone in
     * several rounds moved on from each earlier one and is now in the last round they appear in.
     */
    private function addApplications(Program $program, array $created): void
    {
        $people = []; // email → [round index → applicant row]
        foreach ($created as $index => $c) {
            foreach ($c['applications'] as $a) {
                $email = mb_strtolower(trim((string) ($a['email'] ?? '')));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("Round {$c['round']->round_number}: \"{$email}\" is not a valid email.");
                $people[$email][$index] ??= $a;
            }
        }

        foreach ($people as $email => $rounds) {
            ksort($rounds);
            $details = reset($rounds);
            $user = $this->applicantAccount($email, $details);
            $application = ProgramApplication::where('program_id', $program->id)
                ->where(fn ($q) => $user ? $q->where('user_id', $user->id) : $q->whereNull('user_id')->whereRaw('LOWER(email) = ?', [$email]))
                ->first() ?? $this->newApplication($program, $user, $email, $details);

            // Someone in a later round moved on from every round before it, even if those rows are not in the file
            $last = array_key_last($rounds);
            $lastRound = $created[$last]['round'];
            $finishedThere = $lastRound->status === 'finalized'; // their last round is done and they did not move on
            // Funding setup: an applicant of the program's last round marked Awarded? Yes was awarded (as finalizing the
            // last round leaves them): they are in the deal room, where their milestones and funding are set up
            $isLastRound = $last === array_key_last($created);
            $awarded = $finishedThere && $isLastRound && ! empty($rounds[$last]['awarded']);
            $score = isset($rounds[$last]['score']) && is_numeric($rounds[$last]['score']) ? max(0, min(100, (float) $rounds[$last]['score'])) : null;
            for ($index = 0; $index <= $last; $index++) {
                $round = $created[$index]['round'];
                $a = $rounds[$index] ?? null;
                $submitted = $this->date($a['submitted_on'] ?? ($details['submitted_on'] ?? null));
                $outcome = $index < $last ? 'advanced' : ($awarded ? 'awarded' : ($finishedThere ? 'not_selected' : 'in_progress'));
                ApplicationRoundHistory::updateOrCreate(
                    ['application_id' => $application->id, 'round_id' => $round->id],
                    ['round_number' => $round->round_number, 'entered_at' => $submitted, 'submitted_at' => $submitted,
                        'outcome' => $outcome, 'exited_at' => $outcome === 'in_progress' ? null : $submitted,
                        // Funding setup without scoring criteria: the one review score the file gives
                        ...($index === $last && $score !== null ? ['average_score' => $score] : [])],
                );
                if ($a) $this->addAnswers($application, $round, $created[$index]['questions'], $a['answers'] ?? []);
            }

            // As the app leaves them: whoever moved on from a round is approved; the round says where they stand now
            $application->forceFill([
                'current_round_id' => $lastRound->id,
                'status' => $awarded ? 'awarded' : ($last > 0 ? 'approved' : 'pending'),
                'round_status' => $awarded ? 'advanced' : ($finishedThere ? 'not_selected' : 'submitted'),
                ...($awarded ? ['awarded_at' => $this->date($rounds[$last]['submitted_on'] ?? null)] : []),
                ...($score !== null ? ['average_score' => $score] : []),
            ])->save();
            if ($awarded) $this->awardedCount++;
            $application->setRelation('currentRound', $lastRound);
            if (! $finishedThere) {
                $this->knockout->evaluate($application);
                $this->matchScore->recalculateApplication($application->fresh());
            }
            $this->applicationIds[] = $application->id;
            $this->applicationByEmail[$email] = $application->id;
        }
    }

    /**
     * Business Coaches of the imported rounds, added the way Round Config adds them (RoundReviewerController@store):
     * the person on the round, waiting to accept, and their work order with the fee. A new outside coach gets a
     * quiet account in the organization (no password). Nothing is sent: they are invited when the person chooses.
     * The program owner listing themself is accepted at once, with no fee.
     */
    /**
     * Start at disbursement: the awardees' milestones as the deal room leaves them once the funding plan is activated
     * (ProgramMilestoneController@activateTemplates): active milestones in order, the first approved for release
     * unless it needs a pre-release check (MPRV), the rest locked until the one before is done; each with its supplier
     * (from the owner's supplier list, added there when new; a milestone paid to the applicant gets the applicant as
     * its payee, paid straight to them) and its budget lines. The awardee's approved and awarded amount is the total
     * of their milestones and their funding setup is completed. Nothing is reserved in the wallet and nothing is
     * sent, as the app does neither at this point.
     */
    private function addMilestones(ProgramImport $import, Program $program, array $milestones): void
    {
        $owner = (int) $program->user_id;
        $byAwardee = collect($milestones)->groupBy(fn ($m) => mb_strtolower(trim((string) ($m['awardee'] ?? ''))));
        foreach ($byAwardee as $email => $list) {
            $applicationId = $this->applicationByEmail[$email] ?? null;
            $application = $applicationId ? ProgramApplication::find($applicationId) : null;
            if (! $application || $application->status !== 'awarded') throw new RuntimeException("{$email}: milestones are only for awardees (Awarded? Yes).");

            $first = true;
            $total = 0.0;
            // Monitoring & evaluation: every milestone before this one is completed (so this one may be under way)
            $allDone = true;
            foreach ($list->sortBy(fn ($m) => (int) ($m['order'] ?? 0))->values() as $index => $m) {
                $amount = round(max(0, (float) ($m['amount'] ?? 0)), 2);
                if ($amount <= 0) throw new RuntimeException("{$email}, milestone ".($index + 1).': the amount must be above 0.');
                $mprv = ! empty($m['mprv']);
                $milestone = \App\Models\Programs\ProgramMilestone::create([
                    'app_id' => $application->id,
                    'sequence_order' => $index + 1,
                    'title' => $this->clip($m['title'] ?? null, 500) ?? 'Milestone '.($index + 1),
                    'description' => $this->clip($m['description'] ?? null, 1000) ?? '',
                    'amount' => $amount,
                    'expected_outcomes' => $m['outcomes'] ?? null,
                    'purpose_type' => in_array($m['purpose'] ?? null, ['capex', 'opex', 'services', 'mixed'], true) ? $m['purpose'] : null,
                    'estimated_completion_date' => ! empty($m['target_date']) ? $this->date($m['target_date'])->toDateString() : null,
                    'status' => 'pending',
                    'fund_release_status' => $first && ! $mprv ? 'approved' : 'locked',
                    'is_template' => false,
                    'created_by_role' => 'program_owner',
                    'mprv_required' => $mprv,
                    'mid_milestone_required' => ! empty($m['mid_check']),
                    'final_approval_required' => ! empty($m['final_check']),
                ]);
                $first = false;
                $total += $amount;

                // Who it pays follows the program's disbursement type, as the deal room allows: supplier → a supplier,
                // beneficiary → the applicant, hybrid → either
                $type = $program->disbursement_type ?: 'supplier';
                $applicant = ($m['paid_to'] ?? ($type === 'beneficiary' ? 'applicant' : 'supplier')) === 'applicant';
                if ($applicant && $type === 'supplier') throw new RuntimeException("{$email}, milestone ".($index + 1).': this program pays suppliers, so the milestone cannot be paid to the applicant.');
                if (! $applicant && $type === 'beneficiary') throw new RuntimeException("{$email}, milestone ".($index + 1).': this program pays the applicant, so the milestone cannot be paid to a supplier.');
                $addedBy = ! $applicant && ($m['supplier_by'] ?? '') === 'applicant' ? 'applicant' : 'program_owner';
                $supplier = $applicant ? $this->applicantPayee($application, $owner) : $this->supplierOf($m['supplier'] ?? [], $owner, $email, $index + 1);
                $link = \App\Models\Programs\MilestoneSupplier::create([
                    'milestone_id' => $milestone->id,
                    'supplier_id' => $supplier->id,
                    'assignment_type' => 'primary',
                    'payment_route' => $applicant ? 'direct_to_applicant' : 'direct_to_supplier',
                    'quoted_amount' => $amount,
                    // Who brought the supplier in: the program owner (the default) or the applicant
                    'added_by' => $addedBy,
                ]);
                if (! $applicant) $this->supplierLinkIds[] = $link->id;
                // Monitoring & evaluation: where the milestone has got, with the checks and payments already done
                if (isset($m['progress'])) {
                    $allDone = $this->applyProgress($milestone, $link, $m, $application, $applicant, $owner, $allDone, "{$email}, milestone ".($index + 1));
                }
                foreach ($m['budget'] ?? [] as $line) {
                    $unit = round(max(0, (float) ($line['unit_cost'] ?? 0)), 2);
                    $quantity = max(1, (int) ($line['quantity'] ?? 1));
                    \App\Models\Programs\MilestoneBudgetItem::create([
                        'milestone_id' => $milestone->id,
                        'supplier_id' => $link->id,
                        'item_description' => $this->clip($line['item'] ?? null, 500) ?? '',
                        'unit_cost' => $unit,
                        'quantity' => $quantity,
                        'total_cost' => round($unit * $quantity, 2),
                        'purpose_type' => in_array($line['purpose'] ?? null, ['capex', 'opex', 'services', 'mixed'], true) ? $line['purpose'] : null,
                        'added_by' => $addedBy,
                    ]);
                }
            }

            $application->forceFill([
                'total_amount_requested' => $total,
                'awarded_amount' => $total,
                'funding_setup_status' => 'completed',
            ])->save();
        }
    }

    /**
     * Start at monitoring & evaluation: a milestone as the deal room leaves it once it has got this far, in order:
     *  - completed: paid, and its completion and every check it needs approved (as approving a completion leaves it);
     *  - paid: its funds released (as a successful release leaves it), work going on;
     *  - ready: its MPRV check approved, waiting to be paid;
     *  - not started: locked, unless every milestone before it is completed and it needs no MPRV (then it can be paid,
     *    as the app unlocks the next milestone).
     * Each check a done stage needed is recorded as approved by the program owner, and each payment already made is
     * recorded as a completed disbursement of the milestone (a record only: nothing is paid and no wallet changes).
     * @return bool whether every milestone so far is completed
     */
    private function applyProgress(\App\Models\Programs\ProgramMilestone $milestone, \App\Models\Programs\MilestoneSupplier $link, array $m, ProgramApplication $application, bool $applicant, int $owner, bool $allDone, string $where): bool
    {
        $progress = in_array($m['progress'] ?? null, ['not_started', 'ready', 'paid', 'completed'], true) ? $m['progress'] : 'not_started';
        if (! $allDone && $progress !== 'not_started') throw new RuntimeException("{$where}: milestones are done in order, and an earlier one is not completed yet.");
        $payments = collect($m['payments'] ?? [])->filter(fn ($p) => (float) ($p['amount'] ?? 0) > 0)->values();
        if ($payments->isNotEmpty() && in_array($progress, ['not_started', 'ready'], true)) throw new RuntimeException("{$where}: it has payments, so it cannot be ".str_replace('_', ' ', $progress).'.');
        $paidTotal = round($payments->sum(fn ($p) => (float) $p['amount']), 2);
        if ($paidTotal > (float) $milestone->amount + 0.009) throw new RuntimeException("{$where}: the payments add up to more than the milestone.");

        $paid = in_array($progress, ['paid', 'completed'], true);
        $lastPaid = $payments->map(fn ($p) => ! empty($p['paid_on']) ? $this->date($p['paid_on']) : null)->filter()->max();
        $when = $lastPaid ?? now();
        $checks = array_filter([
            'mprv' => $milestone->mprv_required && $progress !== 'not_started',
            'mid_milestone' => $milestone->mid_milestone_required && $progress === 'completed',
            'final_approval' => $milestone->final_approval_required && $progress === 'completed',
        ]);
        foreach (array_keys($checks) as $type) {
            \App\Models\Programs\MilestoneVerification::create([
                'milestone_id' => $milestone->id,
                'submitted_by' => $application->user_id ?? $owner,
                'attempt_number' => 1,
                'conflict_of_interest_confirmed' => true,
                'funds_usage_confirmed' => true,
                'verification_type' => $type,
                'decision' => 'approved',
                'decided_by' => $owner,
                'decided_at' => $when,
                'decision_notes' => 'Recorded by import: approved before the program came to Tujitume.',
            ]);
        }

        $fields = match ($progress) {
            'completed' => ['status' => 'completion_approved', 'fund_release_status' => 'released', 'fund_released' => true, 'completed_at' => $when, 'completion_approved_by' => $owner, 'completion_approved_at' => $when],
            'paid' => ['status' => 'completed', 'fund_release_status' => 'released', 'fund_released' => true],
            'ready' => ['status' => 'approved', 'fund_release_status' => 'approved'],
            default => ['status' => 'pending', 'fund_release_status' => $allDone && ! $milestone->mprv_required ? 'approved' : 'locked'],
        };
        if ($progress !== 'not_started') $fields += ['approved_by' => $owner, 'approved_at' => $when];
        // Unlocked because the milestone before it is completed, as approving that completion does (status approved,
        // by the owner); the first milestone stays pending with its release open, as activating the plan leaves it
        if ($fields['fund_release_status'] === 'approved' && $progress === 'not_started' && (int) $milestone->sequence_order > 1) {
            $fields += ['status' => 'approved', 'approved_by' => $owner, 'approved_at' => now()];
            $fields['status'] = 'approved';
        }
        $milestone->forceFill($fields + [
            'mprv_ready' => isset($checks['mprv']),
            'mid_milestone_ready' => isset($checks['mid_milestone']),
            'final_approval_ready' => isset($checks['final_approval']),
        ])->save();

        if ($progress === 'completed') {
            \App\Models\Milestones\MilestoneCompletionSubmission::create([
                'milestone_id' => $milestone->id,
                'submitted_by' => $application->user_id ?? $owner,
                'attempt_number' => 1,
                'completion_report' => 'Recorded by import: completed before the program came to Tujitume.',
                'decision' => 'approved',
                'decided_by' => $owner,
                'decided_at' => $when,
            ]);
        }

        if ($paid) {
            // A milestone marked paid with no payment listed: recorded as paid in full on the import date
            if ($payments->isEmpty()) $payments = collect([['amount' => (float) $milestone->amount, 'paid_on' => null, 'channel' => null, 'reference' => null]]);
            foreach ($payments as $p) {
                \App\Models\Programs\Disbursement::create([
                    'milestone_id' => $milestone->id,
                    // The program's own wallet, as a release records it, so the payment shows in the payments ledger
                    'wallet_id' => $application->program?->wallet?->id,
                    'supplier_id' => $applicant ? null : $link->id,
                    'recipient_type' => $applicant ? 'business_owner' : 'supplier',
                    'recipient_user_id' => $applicant ? $application->user_id : null,
                    'amount' => round((float) $p['amount'], 2),
                    'currency' => $application->program?->currency ?: config('app.currency', 'KES'),
                    'payment_method' => in_array($p['channel'] ?? null, ['mpesa_paybill', 'mpesa_mobile', 'mpesa_till', 'bank_transfer', 'pochi', 'other'], true) ? $p['channel'] : 'other',
                    'payment_reference' => $this->clip($p['reference'] ?? null, 100) ?? 'Imported',
                    'disbursed_at' => ! empty($p['paid_on']) ? $this->date($p['paid_on']) : now(),
                    'status' => 'completed',
                    // Only the supplier's own confirmation (their emailed link) sets this in the app
                    'supplier_confirmed' => false,
                    'authorized_by' => $owner,
                ]);
            }
            // What was paid is no longer available to the program, as a release leaves it (a figure on the program:
            // no wallet or M-Pesa money moves)
            $application->program?->decrement('available_amount', round($payments->sum(fn ($p) => (float) $p['amount']), 2));
        }

        return $allDone && $progress === 'completed';
    }

    /**
     * Start at monitoring & evaluation: each awardee's checkpoints as the M&E tab keeps them (sent, so the awardee
     * sees them; nobody is notified while importing), the report already received as their submission, and the site
     * visit with who visits it (made the way Round Config makes Business Coaches; told only when the person chooses).
     */
    private function addCheckpoints(ProgramImport $import, Program $program, array $checkpoints): void
    {
        $owner = (int) $program->user_id;
        $types = ['monitoring', 'reporting', 'meeting', 'site_visit', 'milestone_review', 'financial_audit', 'kpi_assessment', 'impact_evaluation', 'disbursement_approval', 'closure_review'];
        $seen = [];
        foreach ($checkpoints as $c) {
            $email = mb_strtolower(trim((string) ($c['awardee'] ?? '')));
            $name = $this->clip($c['name'] ?? null, 255);
            $where = "{$email}, checkpoint \"{$name}\"";
            $applicationId = $this->applicationByEmail[$email] ?? null;
            $application = $applicationId ? ProgramApplication::find($applicationId) : null;
            if (! $application || $application->status !== 'awarded') throw new RuntimeException("{$email}: checkpoints are only for awardees.");
            if (! $name) throw new RuntimeException("{$email}: a checkpoint needs a name.");
            if (isset($seen["{$email}|".mb_strtolower($name)])) throw new RuntimeException("{$where} is listed twice.");
            $seen["{$email}|".mb_strtolower($name)] = true;

            $visit = is_array($c['visit'] ?? null) ? $c['visit'] : null;
            $siteVisit = $visit || ! empty($c['site_visit']);
            $status = in_array($c['status'] ?? null, ['pending', 'submitted', 'verified', 'changes_requested'], true) ? $c['status'] : 'pending';
            // Who reports, as the M&E tab sets it: a site visit is the officer's to report unless your team does it
            $respondent = ($c['respondent'] ?? '') === 'organization' ? 'organization' : ($siteVisit && ($c['respondent'] ?? '') !== 'applicant' ? 'site_visit_officer' : 'applicant');
            $checkpoint = \App\Models\Programs\Monitoring\MECheckpoint::create([
                'app_id' => $application->id,
                'program_id' => $program->id,
                'checkpoint_name' => $name,
                'type' => in_array($c['type'] ?? null, $types, true) ? $c['type'] : ($siteVisit ? 'site_visit' : 'monitoring'),
                'due_date' => ! empty($c['due_date']) ? $this->date($c['due_date'])->toDateString() : null,
                'requirement' => $this->clip($c['requirement'] ?? null, 5000),
                // What it is about, tracks and asks for, in the M&E tab's own shapes (the page builds them with its helpers)
                'checkpoint_description' => $this->clip($c['description'] ?? null, 500),
                'kpis_to_track' => $this->jsonList($c['kpis_to_track'] ?? null, 50),
                'evidence_required' => $this->jsonList($c['evidence_required'] ?? null, 50),
                'submission_fields' => $this->jsonList($c['submission_fields'] ?? null, 20) ?: [['key' => 'report', 'label' => 'Written progress report', 'type' => 'report', 'required' => true]],
                'custom_submission_fields' => [],
                'require_site_visit' => $siteVisit,
                'respondent' => $respondent,
                'status' => $status,
                // 0, 1, 2… as the M&E tab numbers them
                'display_order' => max(0, (int) ($c['order'] ?? 1) - 1),
                'should_notify_applicant' => false,
                // Sent, as the M&E tab's checkpoints are: the awardee sees it (nothing is sent while importing)
                'sent_at' => now(),
            ]);

            // The site visit first, so a report received for it is the officer's
            $officerId = $visit ? $this->addSiteVisit($import, $program, $application, $checkpoint, $visit, $where) : null;
            if ($status !== 'pending') {
                $reviewed = in_array($status, ['verified', 'changes_requested'], true);
                \App\Models\Programs\Monitoring\MESubmission::create([
                    'checkpoint_id' => $checkpoint->id,
                    'app_id' => $application->id,
                    'submitted_by' => match ($respondent) { 'applicant' => $application->user_id ?? $owner, 'site_visit_officer' => $officerId ?? $owner, default => $owner },
                    'written_report' => $this->clip($c['report'] ?? null, 60000) ?? 'Recorded by import: report received before the program came to Tujitume.',
                    // The KPI results the report gave, as the monitoring form saves them
                    'kpi_actual_values' => $this->jsonList($c['kpi_actual_values'] ?? null, 50),
                    'status' => $status,
                    'reviewer_note' => $reviewed ? $this->clip($c['review_note'] ?? null, 5000) : null,
                    'reviewed_by' => $reviewed ? $owner : null,
                    'reviewed_at' => $reviewed ? now() : null,
                    'submitted_at' => ! empty($c['submitted_on']) ? $this->date($c['submitted_on']) : now(),
                ]);
            }

        }
    }

    /** A checkpoint's site visit and its order, as assigning one in the M&E tab makes them (SiteVisitController). */
    /** @return int the officer's user id */
    private function addSiteVisit(ProgramImport $import, Program $program, ProgramApplication $application, \App\Models\Programs\Monitoring\MECheckpoint $checkpoint, array $v, string $where): int
    {
        $email = mb_strtolower(trim((string) ($v['email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("{$where}: the site visit needs the officer's email.");
        if (empty($v['visit_date'])) throw new RuntimeException("{$where}: the site visit needs its date.");
        $team = ($v['kind'] ?? '') === 'team';
        $found = ImportCoachAccount::find($email, $team ? 'team' : 'coach', (int) $import->organization_id);
        if ($found['problem'] === 'coachNotInTeam') throw new RuntimeException("{$where}: {$email} is not on your team.");
        if ($found['problem'] === 'coachEmailTaken') throw new RuntimeException("{$where}: {$email} belongs to another Tujitume account, so it cannot visit for you.");
        $user = $found['user'] ?? ($found['as'] === 'new_team' ? $this->newTeamMember($import, $email, $v, $where) : $this->newCoach($import, $email, $v, $where));
        if ($found['as'] === 'provider' && ! $found['listed'] && OrganizationProvider::available()) {
            OrganizationProvider::firstOrCreate(['organization_id' => $import->organization_id, 'user_id' => $user->id], ['added_by' => $import->user_id]);
        }

        $done = ($v['status'] ?? '') === 'completed';
        $date = $this->date($v['visit_date']);
        $business = $application->business;
        $visit = \App\Models\Programs\Monitoring\MESiteVisit::create([
            'checkpoint_id' => $checkpoint->id,
            'app_id' => $application->id,
            'reviewer_id' => $user->id,
            'inspector' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: $email,
            'start_date' => $date->toDateString(),
            'assign_type' => $team ? 'internal' : ($found['as'] === 'provider' ? 'third_party_audit' : 'external'),
            'email' => $email,
            'location' => $this->clip($v['location'] ?? null, 255) ?? $business?->location,
            'gps_lat' => $business?->lat,
            'gps_lng' => $business?->lng,
            'objective' => $this->clip($v['objective'] ?? null, 5000) ?? $checkpoint->requirement,
            'observed_actions' => $done ? $this->clip($v['findings'] ?? null, 10000) : null,
            'recommendation_notes' => $done ? $this->clip($v['recommendation'] ?? null, 10000) : null,
            'status' => $done ? 'completed' : 'scheduled',
        ]);
        ReviewerOrder::create([
            'organization_id' => $program->organization_id,
            'reviewer_id' => $user->id,
            'program_id' => $program->id,
            'order_type' => 'site_visit',
            'site_visit_id' => $visit->id,
            'fee_amount' => 0,
            'currency' => $program->currency ?: config('app.currency', 'KES'),
            // A done visit comes in with its work approved; a scheduled one waits for the officer to accept it
            'work_status' => $done ? 'approved' : 'assigned',
            'acceptance_status' => $done ? 'accepted' : 'pending',
            'accepted_at' => $done ? $date : null,
            'delivered_at' => $done ? $date : null,
            'approved_at' => $done ? $date : null,
            'payment_status' => 'unpaid',
            'deadline' => $date->toDateString(),
        ]);
        if (! $done) $this->siteVisitIds[] = $visit->id;

        return (int) $user->id;
    }

    /** A milestone's supplier, from the owner's supplier list (same email), added there when new. */
    private function supplierOf(array $s, int $owner, string $awardee, int $number): \App\Models\Programs\SupplierDirectory
    {
        $email = mb_strtolower(trim((string) ($s['email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("{$awardee}, milestone {$number}: the supplier needs a valid email.");
        $existing = \App\Models\Programs\SupplierDirectory::where('added_by', $owner)->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing) return $existing;

        $method = (string) ($s['payment_method'] ?? '');
        $number_ = $s['account_number'] ?? null;
        $reference = $s['account_reference'] ?? null;
        $details = match ($method) {
            'mpesa_paybill' => ['mpesa_paybill_number' => $number_, 'mpesa_paybill_account' => $reference],
            'mpesa_till' => ['mpesa_till_number' => $number_, 'mpesa_account_reference' => $reference],
            'mpesa_mobile' => ['lipr_mobile_number' => $number_],
            'mpesa_lipr' => ['lipr_wallet' => $number_],
            'bank_transfer' => ['bank_name' => $s['bank_name'] ?? null, 'bank_account_number' => $number_, 'bank_branch' => $s['bank_branch'] ?? null],
            // Other: paid outside Tujitume (the supplier list allows it; a release cannot pay it)
            'other' => [],
            default => throw new RuntimeException("{$awardee}, milestone {$number}: choose how the supplier is paid."),
        };

        $created = \App\Models\Programs\SupplierDirectory::create([
            'added_by' => $owner,
            'legal_name' => $this->clip($s['name'] ?? null, 255) ?? $email,
            'contact_person' => $this->clip($s['contact'] ?? null, 255),
            'phone' => $this->clip($s['phone'] ?? null, 50),
            'email' => $email,
            'payment_method' => $method,
            'is_active' => true,
        ] + $details);
        $this->newSupplierIds[] = $created->id;

        return $created;
    }

    /**
     * The applicant as the payee of a milestone paid straight to them: the release pays the awardee's own wallet,
     * and the deal room expects every milestone to have a payee, so the awardee is on the owner's list once.
     */
    private function applicantPayee(ProgramApplication $application, int $owner): \App\Models\Programs\SupplierDirectory
    {
        $user = $application->user;
        $email = mb_strtolower(trim((string) ($user?->email ?? $application->email ?? '')));
        $existing = \App\Models\Programs\SupplierDirectory::where('added_by', $owner)->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing) return $existing;

        return \App\Models\Programs\SupplierDirectory::create([
            'added_by' => $owner,
            'user_id' => $user?->id,
            'legal_name' => $this->clip($application->business?->name ?? trim(($user?->first_name ?? '').' '.($user?->last_name ?? '')), 255) ?: $email,
            'phone' => $this->clip($user?->phone ?? null, 50),
            'email' => $email,
            'supplier_type' => 'Applicant',
            'payment_method' => 'mpesa_lipr',
            'lipr_wallet' => $user?->lipr_wallet,
            'is_active' => true,
        ]);
    }

    private function addCoaches(ProgramImport $import, Program $program, array $created): void
    {
        foreach ($created as $c) {
            $round = $c['round'];
            foreach ($c['coaches'] as $coach) {
                $internal = ($coach['reviewer_type'] ?? '') === 'internal';
                $email = mb_strtolower(trim((string) ($coach['email'] ?? '')));
                $where = "Round {$round->round_number}, {$email}";
                if (! in_array($round->assignment_type, $internal ? ['internal', 'both'] : ['external', 'both'], true)) {
                    throw new RuntimeException("{$where}: this round is not reviewed by ".($internal ? 'team members' : 'Business Coaches').'.');
                }
                $found = ImportCoachAccount::find($email, $internal ? 'team' : 'coach', (int) $import->organization_id);
                if ($found['problem'] === 'coachNotInTeam') throw new RuntimeException("{$where}: this email is not on your team.");
                if ($found['problem'] === 'coachEmailTaken') throw new RuntimeException("{$where}: this email belongs to another Tujitume account, so it cannot be a Business Coach here.");
                $user = $found['user'] ?? ($found['as'] === 'new_team' ? $this->newTeamMember($import, $email, $coach, $where) : $this->newCoach($import, $email, $coach, $where));
                if (RoundReviewer::where('round_id', $round->id)->where('user_id', $user->id)->exists()) continue;

                $self = (int) $user->id === (int) $program->user_id;
                $fee = $internal || $self ? 0.0 : max(0, (float) ($coach['reviewer_fee'] ?? 0));
                if ($found['as'] === 'provider') {
                    // A service provider is a third-party coach: paid, and on the organization's provider list
                    if ($fee <= 0) throw new RuntimeException("{$where}: this is a Tujitume service provider, who is paid for reviewing. Set a fee.");
                    if (! $found['listed'] && OrganizationProvider::available()) {
                        OrganizationProvider::firstOrCreate(['organization_id' => $import->organization_id, 'user_id' => $user->id], ['added_by' => $import->user_id]);
                    }
                }
                $feeType = ($coach['reviewer_payment_basis'] ?? '') === 'per_application' && ! $internal ? 'per_application' : 'flat';
                $minimum = isset($coach['minimum_apps_to_get_paid']) ? max(1, (int) $coach['minimum_apps_to_get_paid']) : null;
                if ($fee > 0 && $feeType === 'flat' && ! $minimum) throw new RuntimeException("{$where}: with a flat round fee, say how many applications they must score to be paid.");
                if ($fee > 0 && $feeType === 'per_application') $minimum ??= 1;
                $max = isset($coach['max_apps_assigned']) ? max(1, (int) $coach['max_apps_assigned']) : null;
                if ($max && $minimum && $minimum > $max) throw new RuntimeException("{$where}: the least to score to be paid is more than the most applications they get.");

                $reviewer = RoundReviewer::create([
                    'round_id' => $round->id,
                    'user_id' => $user->id,
                    'reviewer_type' => $internal ? 'internal' : 'external',
                    'max_apps_assigned' => $max,
                    'assigned_percentage' => isset($coach['assigned_percentage']) ? max(1, min(100, (int) $coach['assigned_percentage'])) : null,
                    // The areas they understand, stored as Round Config stores them (JSON list)
                    'expertise_tags' => ! empty($coach['expertise_tags']) && is_array($coach['expertise_tags']) ? json_encode(array_values(array_slice(array_map(fn ($tag) => mb_substr(trim((string) $tag), 0, 60), $coach['expertise_tags']), 0, 20))) : null,
                    'reviewer_fee' => $fee,
                    'fee_type' => $feeType,
                    'fee_currency' => strtolower($program->currency ?: 'kes') === 'usd' ? 'usd' : 'kes',
                    'acceptance_status' => $self ? 'accepted' : 'pending',
                    'accepted_at' => $self ? now() : null,
                ]);
                ReviewerOrder::create([
                    'organization_id' => $program->organization_id,
                    'reviewer_id' => $user->id,
                    'program_id' => $program->id,
                    'order_type' => 'round_review',
                    'round_id' => $round->id,
                    'round_reviewer_id' => $reviewer->id,
                    'fee_amount' => $feeType === 'per_application' ? 0 : $fee,
                    'fee_type' => $feeType,
                    'minimum_apps_to_get_paid' => $fee > 0 ? $minimum : null,
                    'work_status' => 'assigned',
                    'acceptance_status' => $self ? 'accepted' : 'pending',
                    'accepted_at' => $self ? now() : null,
                    'payment_status' => 'unpaid',
                    'deadline' => $round->close_date,
                    'currency' => 'KES',
                ]);
                if (! $self) $this->reviewerIds[] = $reviewer->id;
                $this->paidCoaches[$reviewer->id] = (bool) ($coach['paid'] ?? false);
            }
            if (RoundReviewer::where('round_id', $round->id)->exists()) {
                $steps = array_values(array_unique(array_merge($round->confirmed_steps ?? [], ['reviewers'])));
                $round->forceFill(['confirmed_steps' => $steps])->save();
            }
        }
    }

    /**
     * Scores (Start at finalization and later), saved the way ApplicationScoreController saves them: one row per
     * reviewer per application with every criterion by name and the total worked out by the round's rubric; the
     * scoring coach's assignment completed (one coach per application per round, as the app allows); the application
     * of the round being reviewed scored, with its average; a finished round's history keeps the average. Then each
     * coach's work order is settled as the app leaves it (see settleOrders). Nothing is sent and no money moves.
     */
    private function addScores(Program $program, array $created): void
    {
        foreach ($created as $c) {
            if (! $c['scores']) continue;
            $round = $c['round'];
            $names = collect($round->scoring_criteria ?? [])->pluck('name')->all();
            if (! $names) throw new RuntimeException("Round {$round->round_number}: it has scores but no scoring criteria.");
            $places = RoundReviewer::where('round_id', $round->id)->with('user:id,email')->get()->keyBy(fn ($p) => mb_strtolower((string) $p->user?->email));
            $orders = ReviewerOrder::where('round_id', $round->id)->get()->keyBy('round_reviewer_id');
            $scoredApps = [];

            foreach ($c['scores'] as $score) {
                $email = mb_strtolower(trim((string) ($score['applicant'] ?? '')));
                $applicationId = $this->applicationByEmail[$email] ?? null;
                if (! $applicationId) throw new RuntimeException("Round {$round->round_number}: {$email} is not an applicant of this program.");
                $reviewerEmail = mb_strtolower(trim((string) ($score['reviewer'] ?? '')));
                $place = $reviewerEmail !== '' ? ($places[$reviewerEmail] ?? null) : null;
                if ($reviewerEmail !== '' && ! $place) throw new RuntimeException("Round {$round->round_number}: {$reviewerEmail} is not a Business Coach of this round.");
                $reviewerId = $place ? (int) $place->user_id : (int) $program->user_id;
                // Only an applicant of this round is scored in it
                if (! ApplicationRoundHistory::where('application_id', $applicationId)->where('round_id', $round->id)->exists()) {
                    throw new RuntimeException("Round {$round->round_number}: {$email} did not apply in this round, so they cannot be scored in it.");
                }
                // The app gives each application one Business Coach per round (and only they score it)
                $holder = $place ? ReviewerApplicationAssignment::where('round_id', $round->id)->where('application_id', $applicationId)->value('reviewer_id') : null;
                if ($holder && (int) $holder !== $reviewerId) throw new RuntimeException("Round {$round->round_number}, {$email}: scored by more than one Business Coach. Each application has one Business Coach per round.");

                $criterionScores = collect($score['criterion_scores'] ?? [])->map(fn ($x) => ['criterion' => (string) ($x['criterion'] ?? ''), 'score' => (float) ($x['score'] ?? 0), 'comment' => $x['comment'] ?? null])->values()->all();
                $missing = array_diff($names, array_column($criterionScores, 'criterion'));
                if ($missing) throw new RuntimeException("Round {$round->round_number}, {$email}: no score for ".implode(', ', $missing).'.');
                $criterionScores = array_values(array_filter($criterionScores, fn ($x) => in_array($x['criterion'], $names, true)));
                $scoredAt = $this->date($score['scored_on'] ?? null);

                ApplicationScore::updateOrCreate(
                    ['application_id' => $applicationId, 'round_id' => $round->id, 'reviewer_id' => $reviewerId],
                    ['criterion_scores' => $criterionScores, 'total_score' => $this->rubric->calculate($criterionScores, $round->scoring_criteria, $round->rubric_mode ?? 'weighted'),
                        'overall_comment' => isset($score['comment']) ? mb_substr((string) $score['comment'], 0, 5000) : null, 'scored_at' => $scoredAt, 'source' => 'human'],
                );
                // The coach who scored holds the application's assignment (the app keeps one per application per round)
                if ($place && ! ReviewerApplicationAssignment::where('round_id', $round->id)->where('application_id', $applicationId)->exists()) {
                    ReviewerApplicationAssignment::create([
                        'round_id' => $round->id, 'reviewer_id' => $reviewerId, 'reviewer_order_id' => $orders[$place->id]?->id,
                        'application_id' => $applicationId, 'status' => 'completed', 'assigned_at' => $scoredAt, 'completed_at' => $scoredAt,
                    ]);
                }
                $scoredApps[$applicationId] = true;
            }

            foreach (array_keys($scoredApps) as $applicationId) {
                $application = ProgramApplication::find($applicationId);
                if ($round->status === 'finalized') {
                    // A finished round: its decision is already made; the round's history keeps the average
                    ApplicationRoundHistory::where('application_id', $applicationId)->where('round_id', $round->id)
                        ->update(['average_score' => $this->averager->average($applicationId, $round->id)]);
                } elseif ((int) $application->current_round_id === (int) $round->id) {
                    $this->averager->apply($application, $round);
                }
            }
            $this->settleOrders($round);
        }
    }

    /**
     * Each Business Coach's work order of a round with scores, as the app leaves it. Whoever scored has accepted. A
     * per-application fee is the rate times what they scored. The round being reviewed: their work is delivered when
     * they scored enough to be paid (or, with no fee, everything they were given), else in progress; the owner then
     * approves it and pays it in Tujitume as usual. A finished round: the work is approved (or rejected when they
     * scored less than the least to be paid, as finalizing does); "already paid" is recorded as paid, otherwise it
     * stays to be paid in Tujitume. No money moves here.
     */
    private function settleOrders(ProgramRound $round): void
    {
        $finished = $round->status === 'finalized';
        foreach (RoundReviewer::where('round_id', $round->id)->get() as $place) {
            $order = ReviewerOrder::where('round_reviewer_id', $place->id)->first();
            if (! $order) continue;
            // What they reviewed, as the app counts it for pay: their completed assignments
            $count = ReviewerApplicationAssignment::where('reviewer_order_id', $order->id)->where('status', 'completed')->count();
            // Nothing scored: a round in review waits for them; in a finished round an unpaid coach is left as they are
            if (! $count && (! $finished || (float) $place->reviewer_fee <= 0)) continue;
            $at = ApplicationScore::where('round_id', $round->id)->where('reviewer_id', $place->user_id)->max('scored_at') ?? now();
            if ($place->acceptance_status !== 'accepted') $place->forceFill(['acceptance_status' => 'accepted', 'accepted_at' => $at])->save();

            $rate = (float) $place->reviewer_fee;
            $fee = $order->fee_type === 'per_application' ? $rate * $count : (float) $order->fee_amount;
            $minimum = (int) ($order->minimum_apps_to_get_paid ?? 0);
            $values = ['acceptance_status' => 'accepted', 'accepted_at' => $order->accepted_at ?? $at, 'fee_amount' => $fee];
            if ($finished) {
                $short = $fee > 0 && $minimum && $count < $minimum;
                $values += $short
                    ? ['work_status' => 'rejected', 'rejection_reason' => "Scored {$count} of the {$minimum} applications needed to be paid."]
                    : ['work_status' => 'approved', 'delivered_at' => $at, 'approved_at' => $at];
                if (! $short && ($this->paidCoaches[$place->id] ?? false)) $values += ['payment_status' => 'completed', 'paid_at' => $at];
            } else {
                $given = ReviewerApplicationAssignment::where('reviewer_order_id', $order->id)->count();
                $done = $fee > 0 ? $count >= max(1, $minimum ?: $given) : $count >= $given;
                $values += $done ? ['work_status' => 'delivered', 'delivered_at' => $at] : ['work_status' => 'in_progress'];
            }
            $order->forceFill($values)->save();
        }
    }

    /**
     * A team member new to Tujitume, added to the organization's team as Team Members → Invite does it
     * (RegisterService::registerOrgRoleUser): an account with no password, on the team as an internal reviewer,
     * waiting to accept. Their invitation is sent when the person chooses (Invite Business Coaches).
     */
    private function newTeamMember(ProgramImport $import, string $email, array $coach, string $where): User
    {
        $first = $this->clip($coach['first_name'] ?? null, 100);
        $last = $this->clip($coach['last_name'] ?? null, 100);
        if (! $first || ! $last) throw new RuntimeException("{$where}: a new team member needs a first and last name.");
        $role = \App\Models\Auth\Role::where('name', 'internal_reviewer')->first();
        if (! $role) throw new RuntimeException("{$where}: the team role for reviewers is not set up on this server.");
        $user = User::create([
            'email' => $email, 'first_name' => $first, 'last_name' => $last, 'password' => null,
            'user_type_id' => 4, 'organization_id' => $import->organization_id,
        ]);
        UserSetting::create(['user_id' => $user->id]);
        \App\Models\Auth\OrganizationUserRole::create([
            'organization_id' => $import->organization_id, 'user_id' => $user->id, 'role_id' => $role->id,
            'status' => 'pending', 'invited_by_user_id' => $import->user_id,
        ]);

        return $user;
    }

    /** A Business Coach new to Tujitume: an account in the organization, with no password and nothing sent yet. */
    private function newCoach(ProgramImport $import, string $email, array $coach, string $where): User
    {
        $first = $this->clip($coach['first_name'] ?? null, 100);
        $last = $this->clip($coach['last_name'] ?? null, 100);
        if (! $first || ! $last) throw new RuntimeException("{$where}: a new Business Coach needs a first and last name.");
        $user = User::create([
            'email' => $email, 'first_name' => $first, 'last_name' => $last, 'password' => null,
            'user_type_id' => ImportCoachAccount::COACH_TYPE, 'organization_id' => $import->organization_id,
        ]);
        UserSetting::create(['user_id' => $user->id]);

        return $user;
    }

    /**
     * The applicant's account: theirs when the email already has an applicant account; otherwise a quiet new one
     * (no password and nothing sent). An email that belongs to another kind of account (a team member, a coach)
     * is not linked to it: the application keeps the email, and is claimed when they sign up as an applicant.
     */
    private function applicantAccount(string $email, array $a): ?User
    {
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($user) return (int) $user->user_type_id === self::APPLICANT_TYPE ? $user : null;

        $user = User::create([
            'email' => $email,
            'first_name' => $this->clip($a['first_name'] ?? null, 100),
            'last_name' => $this->clip($a['last_name'] ?? null, 100),
            'phone' => $this->clip($a['phone'] ?? null, 50),
            'user_type_id' => self::APPLICANT_TYPE,
            'password' => null,
            'completed_onboarding' => 0,
        ]);
        UserSetting::create(['user_id' => $user->id]);

        return $user;
    }

    private function newApplication(Program $program, ?User $user, string $email, array $a): ProgramApplication
    {
        $name = trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? ''));
        $amount = isset($a['amount_requested']) && is_numeric($a['amount_requested']) ? (float) $a['amount_requested'] : (float) $program->funding_per_business;

        return ProgramApplication::create([
            'program_id' => $program->id,
            'program_owner_id' => $program->user_id,
            'user_id' => $user?->id,
            'email' => $email,
            'contact_person_email' => $email,
            'contact_person_name' => $this->clip($name !== '' ? $name : $email, 100),
            'startup_name' => $this->clip(($a['business_name'] ?? null) ?: ($name !== '' ? $name : $email), 255),
            'sector' => '',
            'headquarters_location' => '',
            'total_amount_requested' => max(0, $amount),
            'status' => 'pending',
            'round_status' => 'submitted',
            'knockout_status' => 'pending',
        ]);
    }

    /** Answers by the field's place in the round; files and signatures are added later by the applicant. */
    private function addAnswers(ProgramApplication $application, ProgramRound $round, array $questions, array $answers): void
    {
        $rows = [];
        foreach ($answers as $answer) {
            $question = $questions[(int) ($answer['question'] ?? -1)] ?? null;
            $response = $answer['response'] ?? null;
            if (! $question || in_array($question->question_type, ['file_upload', 'signature'], true) || ! is_scalar($response) || trim((string) $response) === '') continue;
            $rows[$question->id] = ['application_id' => $application->id, 'round_id' => $round->id, 'question_id' => $question->id,
                'response' => mb_substr((string) $response, 0, 65000), 'created_at' => now(), 'updated_at' => now()];
        }
        if (! $rows) return;
        ApplicationRoundResponse::where('application_id', $application->id)->whereIn('question_id', array_keys($rows))->delete();
        ApplicationRoundResponse::insert(array_values($rows));
    }

    /** A list from the plan kept as JSON: arrays of plain values or of small objects, at most $max items. */
    private function jsonList(mixed $value, int $max): array
    {
        if (! is_array($value)) return [];

        return array_values(array_slice(array_filter($value, fn ($item) => is_array($item) || is_scalar($item)), 0, $max));
    }

    private function date(mixed $value): Carbon
    {
        try {
            return $value ? Carbon::parse((string) $value)->startOfDay()->setTime(9, 0) : now();
        } catch (\Throwable) {
            return now();
        }
    }

    private function clip(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function roundColumns(ProgramImport $import, Program $program, array $r, int $number): array
    {
        $method = in_array($r['review_method'] ?? null, self::REVIEW_METHODS, true) ? $r['review_method'] : 'manual';
        $advancement = in_array($r['advancement_mode'] ?? null, self::ADVANCEMENT, true) ? $r['advancement_mode'] : 'manual';
        $name = trim((string) ($r['round_name'] ?? ''));
        $rubric = in_array($r['rubric_mode'] ?? null, self::RUBRIC_MODES, true) ? $r['rubric_mode'] : 'weighted';
        $assignment = in_array($r['assignment_type'] ?? null, self::ASSIGNMENT_TYPES, true) ? $r['assignment_type'] : 'owner_only';

        return [
            'program_id' => $program->id,
            'round_number' => $number,
            'round_name' => mb_substr($name !== '' ? $name : "Round {$number}", 0, 100),
            // Round 1 may leave its dates empty: it uses the program's application dates
            'open_date' => $r['open_date'] ?? ($number === 1 ? $program->application_start_date : null),
            'close_date' => $r['close_date'] ?? ($number === 1 ? $program->application_deadline : null),
            // With a time, the round's exact moments (Round 1 without its own dates takes the program's), in the program's
            // zone. Only set when there is one: an empty moment would clear the date it is kept in step with
            ...array_filter([
                'opens_at' => ! empty($r['opens_at']) ? \App\Support\ProgramSchedule::parse($r['opens_at'], $program->timezone ?: $this->zone) : ($number === 1 && empty($r['open_date']) ? $program->application_opens_at : null),
                'closes_at' => ! empty($r['closes_at']) ? \App\Support\ProgramSchedule::parse($r['closes_at'], $program->timezone ?: $this->zone) : ($number === 1 && empty($r['close_date']) ? $program->application_closes_at : null),
            ]),
            'review_period_end' => $r['review_period_end'] ?? null,
            'announcement_date' => $r['announcement_date'] ?? null,
            'review_method' => $method,
            // AI-only was chosen on purpose in the import: it is recorded as confirmed by the person who imported
            'ai_only_acknowledged_at' => $method === 'ai_only' ? now() : null,
            'ai_only_acknowledged_by' => $method === 'ai_only' ? $import->user_id : null,
            'advancement_mode' => $advancement,
            'score_threshold' => $advancement === 'score_threshold' && isset($r['score_threshold']) ? max(0, min(100, (float) $r['score_threshold'])) : null,
            'max_advancing' => $advancement === 'fixed_quota' && isset($r['max_advancing']) ? max(1, (int) $r['max_advancing']) : null,
            // How it is reviewed (Start at review and later; the app's defaults otherwise), as Round Config saves it
            'rubric_mode' => $rubric,
            'scoring_criteria' => $this->criteria($r['scoring_criteria'] ?? null, $rubric),
            'assignment_type' => $assignment,
            'assignment_method' => in_array($r['assignment_method'] ?? null, self::ASSIGNMENT_METHODS, true) ? $r['assignment_method'] : 'manual',
            'min_reviewers_required' => max(1, min(20, (int) ($r['min_reviewers_required'] ?? 1))),
            // Where the round is (Start at review and later): finished rounds are finalized, the round being reviewed is
            // published (as the app runs it), and rounds not started stay drafts. Set up imports: drafts.
            'status' => $this->setupOnly ? 'draft' : ['finished' => 'finalized', 'in_review' => 'published'][$r['status'] ?? ''] ?? 'draft',
            // Setup and the application fields came in with the import. From Start at review, a round reviewed by
            // the owner only has nobody to add on the Business Coaches step, so that step is done too.
            'confirmed_steps' => $import->stage !== 'setup' && ! $this->setupOnly && $assignment === 'owner_only' ? ['setup', 'questions', 'reviewers'] : ['setup', 'questions'],
        ];
    }

    /** Scoring criteria as Round Config saves them; in a weighted round the weights must add up to 100. */
    private function criteria(mixed $criteria, string $rubric): ?array
    {
        if (! is_array($criteria) || ! $criteria) return null;
        $list = [];
        foreach (array_values($criteria) as $c) {
            $name = mb_substr(trim((string) ($c['name'] ?? '')), 0, 255);
            if ($name === '') continue;
            if (collect($list)->contains(fn ($x) => mb_strtolower($x['name']) === mb_strtolower($name))) throw new RuntimeException("The scoring criterion \"{$name}\" is used twice in one round.");
            $list[] = [
                'name' => $name,
                'description' => mb_substr(trim((string) ($c['description'] ?? '')), 0, 1000),
                'weight' => $rubric === 'weighted' ? max(0, min(100, (int) ($c['weight'] ?? 0))) : 0,
                'scoreRange' => in_array($c['scoreRange'] ?? null, self::SCORE_RANGES, true) ? $c['scoreRange'] : '0-10',
            ];
        }
        if ($rubric === 'weighted' && $list && array_sum(array_column($list, 'weight')) !== 100) {
            throw new RuntimeException('The scoring criteria weights of a round must add up to 100.');
        }

        return $list ?: null;
    }

    private function addQuestion(ProgramRound $round, array $question, int $order): RoundCustomQuestion
    {
        $data = array_intersect_key($question, array_flip(self::QUESTION_KEYS));
        $data['round_id'] = $round->id;
        $data['display_order'] = $order;
        $data['question_text'] = mb_substr(trim((string) ($data['question_text'] ?? '')), 0, 500);
        $data['is_required'] = (bool) ($data['is_required'] ?? false);
        if (($data['question_type'] ?? '') !== 'knockout') $data['knockout_fail_value'] = null;
        try {
            $data = $this->matchScore->prepareQuestionForSave($round, $data);
        } catch (ValidationException $e) {
            throw new RuntimeException("Round {$round->round_number}, field {$order}: ".collect($e->errors())->flatten()->first());
        }
        return RoundCustomQuestion::create($data);
    }
}
