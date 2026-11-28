<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponseResource;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramImport;
use App\Service\Program\ExistingProgramState;
use App\Service\Program\ProgramAccess;
use App\Service\Program\ProgramImportChecker;
use App\Service\Program\ProgramImportAiFixer;
use App\Service\AiReview\LlmException;
use App\Jobs\InviteImportedCoachesJob;
use App\Jobs\NotifyImportedApplicantsJob;
use App\Jobs\RunProgramImportJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Program imports (Programs → Imports): saves where the person is, so an import can be continued later.
 * An import belongs to the organization: every program-team member can see it; what each role may change is
 * decided by ProgramMiddleware (Viewer: read only, Editor: no deleting). The file is kept on the private local disk.
 */
class ProgramImportController extends Controller
{
    private const DISK = 'local';
    private const MAX_FILE_KB = 10240; // 10 MB, the same as the import page
    private const MAX_DATA_BYTES = 8_000_000; // the page's fixes, as JSON (500 programs with their rounds and fields fit)

    /** The signed-in user's organization, when they are on a program team; otherwise 403. */
    private function organizationId(): int
    {
        $user = auth()->user();
        abort_unless($user && (int) $user->user_type_id === 4 && $user->organization_id, 403, ProgramAccess::DENIED);

        return (int) $user->organization_id;
    }

    private function authorizeImport(ProgramImport $import): void
    {
        abort_unless((int) $import->organization_id === $this->organizationId(), 403, ProgramAccess::DENIED);
    }

    /** For "add to a program you have": the program must be one this organization runs. */
    private function authorizeProgram(?int $programId): void
    {
        if (! $programId) return;
        abort_unless(ProgramAccess::owns(Program::find($programId)), 403, ProgramAccess::DENIED);
    }

    /**
     * Add to a program you have: the stage the program is at must be one the import can bring it up to from where it
     * is (ExistingProgramState::allowed). The stage it is at is kept with the import (current_stage), worked out here.
     */
    private function checkExistingStage(array &$data, ?ProgramImport $import = null): void
    {
        $programId = $data['program_id'] ?? $import?->program_id;
        $stage = $data['stage'] ?? $import?->stage;
        if (! $programId || ! $stage) return;
        $state = ExistingProgramState::of(Program::findOrFail($programId));
        $data['current_stage'] = $state['stage'];
        // An older import of new rounds (chosen as "setup" before the stage could be picked) keeps working
        if ($stage === 'setup' && ! ExistingProgramState::addsFunding($stage)) return;
        abort_unless(in_array($stage, $state['allowed'], true), 422, 'This program is at '.$state['stage'].'. Choose a stage from there on: '.implode(', ', $state['allowed']).'.');
    }

    /** GET imports/program-state/{program}: where one of your programs is, for "Add to a program you have" */
    public function programState(Program $program)
    {
        $this->authorizeProgram($program->id);

        return ApiResponseResource::success('Program state', ExistingProgramState::of($program));
    }

    private function choiceRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'mode' => [$req, Rule::in(ProgramImport::MODES)],
            'program_id' => ['nullable', 'integer', 'exists:programs,id', Rule::requiredIf(fn () => request('mode') === 'existing')],
            'program_industry_id' => ['nullable', 'integer', 'exists:program_industries,id'],
            // A many-programs import has no single stage: each program row has its own
            'stage' => [$create ? 'required_unless:mode,many' : 'sometimes', 'nullable', Rule::in(ProgramImport::STAGES)],
            'current_stage' => ['nullable', Rule::in(ProgramImport::STAGES)],
            'step' => ['sometimes', Rule::in(ProgramImport::STEPS)],
            // What the page holds after the person fixed it: program details, rounds and question rows
            'data' => ['sometimes', 'nullable', 'array', function ($attribute, $value, $fail) {
                if (strlen(json_encode($value)) > self::MAX_DATA_BYTES) $fail('This import is too large to save. Split it into smaller files.');
            }],
        ];
    }

    public function index()
    {
        // The list leaves out `data` (the page's fixes, which can be large): it is not shown here, and sorting
        // rows that carry it can use more memory than the database allows for a sort.
        $imports = ProgramImport::query()
            ->select(['id', 'organization_id', 'user_id', 'mode', 'program_id', 'program_industry_id', 'stage', 'current_stage', 'step', 'file_path', 'file_name', 'file_size', 'status', 'progress', 'started_at', 'finished_at', 'created_at', 'updated_at'])
            ->with(['program:id,program_title', 'user:id,first_name,last_name'])
            ->where('organization_id', $this->organizationId())
            ->latest('updated_at')
            ->get();

        return ApiResponseResource::success('Imports', $imports->map(fn ($import) => $import->toView(withData: false))->values());
    }

    public function store(Request $request)
    {
        $organizationId = $this->organizationId();
        $data = $request->validate($this->choiceRules(true));
        if ($data['mode'] !== 'existing') $data['program_id'] = null;
        // Bring programs: the stage chosen applies to every program in the file; none chosen yet → mixed
        if ($data['mode'] === 'many' && empty($data['stage'])) $data['stage'] = ProgramImport::MIXED_STAGE;
        $this->authorizeProgram($data['program_id'] ?? null);
        if ($data['mode'] === 'existing') $this->checkExistingStage($data);
        // Start from a form: one program set up from a Google Form's questions
        if ($data['mode'] === 'form') $data['stage'] = 'setup';

        $import = ProgramImport::create($data + [
            'organization_id' => $organizationId,
            'user_id' => auth()->id(),
            'step' => $data['step'] ?? 'upload',
            'status' => 'draft',
        ]);

        return ApiResponseResource::success('Import started.', $import->load(['program:id,program_title', 'user:id,first_name,last_name'])->toView(), 201);
    }

    /** ?light=1 leaves out the tables (for following step 5's progress every few seconds). */
    public function show(Request $request, ProgramImport $import)
    {
        $this->authorizeImport($import);
        $this->resumeIfStalled($import);
        $this->resumeSendsIfStalled($import);

        return ApiResponseResource::success('Import', $import->load(['program:id,program_title', 'user:id,first_name,last_name'])->toView(withData: ! $request->boolean('light')));
    }

    /** Saves the step reached and any change to the choices. Changing the choices drops the file: it no longer fits. */
    public function update(Request $request, ProgramImport $import)
    {
        $this->authorizeImport($import);
        abort_unless($import->editable(), 422, 'This import is running or finished and can no longer be changed.');
        $data = $request->validate($this->choiceRules(false));
        $mode = $data['mode'] ?? $import->mode;
        if ($mode !== 'existing') $data['program_id'] = null;
        if ($mode !== 'many' && ($data['stage'] ?? $import->stage) === ProgramImport::MIXED_STAGE) abort(422, 'Choose the stage your program has reached.');
        $this->authorizeProgram($data['program_id'] ?? null);
        if ($mode === 'existing' && (array_key_exists('stage', $data) || array_key_exists('program_id', $data))) $this->checkExistingStage($data, $import);
        if ($mode === 'form') $data['stage'] = 'setup';

        $choiceChanged = collect(['mode', 'program_id', 'program_industry_id', 'stage'])
            ->contains(fn ($key) => array_key_exists($key, $data) && (string) $data[$key] !== (string) $import->{$key});
        if ($choiceChanged) $this->forgetFile($import);

        $import->update($data);

        return ApiResponseResource::success('Import saved.', $import->load(['program:id,program_title', 'user:id,first_name,last_name'])->toView());
    }

    public function uploadFile(Request $request, ProgramImport $import)
    {
        $this->authorizeImport($import);
        abort_unless($import->editable(), 422, 'This import is running or finished and can no longer be changed.');
        $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_FILE_KB, function ($attribute, $file, $fail) {
                if (! in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'csv'], true)) {
                    $fail('Please choose an Excel (.xlsx) or CSV file.');
                }
            }],
        ]);

        $file = $request->file('file');
        $this->forgetFile($import);
        $path = $file->storeAs(
            "program-imports/{$import->organization_id}/{$import->id}",
            Str::uuid().'.'.strtolower($file->getClientOriginalExtension()),
            self::DISK,
        );
        $import->update(['file_path' => $path, 'file_name' => Str::limit($file->getClientOriginalName(), 250, ''), 'file_size' => $file->getSize()]);

        return ApiResponseResource::success('File saved.', $import->load(['program:id,program_title', 'user:id,first_name,last_name'])->toView());
    }

    public function downloadFile(ProgramImport $import)
    {
        $this->authorizeImport($import);
        abort_unless($import->file_path && Storage::disk(self::DISK)->exists($import->file_path), 404, 'This import has no file yet.');

        return Storage::disk(self::DISK)->download($import->file_path, $import->file_name);
    }

    public function removeFile(ProgramImport $import)
    {
        $this->authorizeImport($import);
        abort_unless($import->editable(), 422, 'This import is running or finished and can no longer be changed.');
        $this->forgetFile($import);
        $import->save();

        return ApiResponseResource::success('File removed.', $import->load(['program:id,program_title', 'user:id,first_name,last_name'])->toView());
    }

    /** Step 3: checks the import against what is already in Tujitume. Nothing is created. */
    public function check(ProgramImport $import, ProgramImportChecker $checker)
    {
        $this->authorizeImport($import);
        abort_unless(($import->data['version'] ?? null) === 2, 422, 'Add the import file and fix it in step 2 first.');

        return ApiResponseResource::success('Import checked.', $checker->check($import, auth()->user()->organizationOwnerId()));
    }

    /**
     * Amari's fixes for rows with problems. Nothing is stored here: the page shows them as suggestions, which the
     * person approves or edits before anything changes. Up to 40 problems per request (one per kind of problem).
     */
    public function aiFix(Request $request, ProgramImport $import, ProgramImportAiFixer $fixer)
    {
        $this->authorizeImport($import);
        $data = $request->validate([
            'problems' => ['required', 'array', 'min:1', 'max:40'],
            'problems.*.key' => ['required', 'string', 'max:100'],
            'problems.*.table' => ['required', Rule::in(['programs', 'rounds', 'fields', 'criteria', 'coaches', 'applicants', 'scores'])],
            'problems.*.message' => ['required', 'string', 'max:500'],
            // Where it is, in plain words, and the program's dates and rounds, so the AI can tell what really happened
            'problems.*.where' => ['nullable', 'string', 'max:300'],
            'problems.*.context' => ['nullable', 'array'],
            'problems.*.cells' => ['present', 'array'],
            'problems.*.cells.*' => ['nullable', 'string', 'max:2000'],
            // The conversation in the AI drawer so far: the person can ask for fixes another way
            'messages' => ['sometimes', 'array', 'max:12'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:1500'],
        ]);

        try {
            return ApiResponseResource::success('Suggestions ready.', $fixer->suggest($data['problems'], $data['messages'] ?? []));
        } catch (LlmException $e) {
            return ApiResponseResource::error('AI help is not available right now. You can still fix these yourself.', null, 503);
        }
    }

    /**
     * Step 5: starts creating the programs. The page sends the plan (what to create, in the form the app saves),
     * built from the checked import with the person's decisions. The work runs as a job (RunProgramImportJob):
     * with a queue worker it runs there; without one (the sync queue) it runs right after this response.
     * Starting again after a partial import carries on: programs already created are skipped.
     */
    public function start(Request $request, ProgramImport $import)
    {
        $this->authorizeImport($import);
        abort_unless($import->editable(), 422, 'This import is running or finished and can no longer be changed.');
        $request->validate([
            'plan' => ['required', 'array', 'min:1', 'max:500'],
            'plan.*.code' => ['nullable', 'string', 'max:50'],
            // Start at disbursement: the awardees' milestones (with supplier and budget lines)
            'plan.*.milestones' => ['sometimes', 'array', 'max:5000'],
            // Start at monitoring & evaluation: how far each milestone got and its payments, and each awardee's checkpoints
            'plan.*.milestones.*.progress' => ['sometimes', 'nullable', Rule::in(['not_started', 'ready', 'paid', 'completed'])],
            'plan.*.milestones.*.payments' => ['sometimes', 'array', 'max:100'],
            'plan.*.milestones.*.payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'plan.*.milestones.*.payments.*.paid_on' => ['nullable', 'date', 'before_or_equal:'.now()->addDay()->toDateString()], // the person's today may be ahead of the server's (UTC)
            'plan.*.checkpoints' => ['sometimes', 'array', 'max:5000'],
            'plan.*.checkpoints.*.awardee' => ['required', 'email', 'max:100'],
            'plan.*.checkpoints.*.name' => ['required', 'string', 'max:255'],
            'plan.*.checkpoints.*.report' => ['nullable', 'string', 'max:60000'],
            'plan.*.checkpoints.*.visit' => ['sometimes', 'array'],
            'plan.*.checkpoints.*.visit.email' => ['required_with:plan.*.checkpoints.*.visit', 'email', 'max:100'],
            'plan.*.checkpoints.*.visit.visit_date' => ['required_with:plan.*.checkpoints.*.visit', 'date'],
            'plan.*.program_id' => ['nullable', 'integer'],
            // A program already in the account (same title) that the person chose to bring in again as a copy
            'plan.*.copy' => ['sometimes', 'boolean'],
            'plan.*.program' => ['nullable', 'array'],
            'plan.*.rounds' => ['present', 'array', 'max:10'],
            'plan.*.rounds.*.questions' => ['present', 'array', 'max:300'],
            'plan.*.rounds.*.scoring_criteria' => ['nullable', 'array', 'max:30'],
            'plan.*.rounds.*.scoring_criteria.*.name' => ['required', 'string', 'max:255'],
            'plan.*.rounds.*.scoring_criteria.*.weight' => ['nullable', 'integer', 'min:0', 'max:100'],
            'plan.*.rounds.*.applications' => ['sometimes', 'array', 'max:5000'],
            'plan.*.rounds.*.scores' => ['sometimes', 'array', 'max:20000'],
            'plan.*.rounds.*.scores.*.applicant' => ['required', 'email', 'max:100'],
            'plan.*.rounds.*.scores.*.criterion_scores' => ['required', 'array', 'max:30'],
            'plan.*.rounds.*.applications.*.email' => ['required', 'email', 'max:100'],
            'plan.*.rounds.*.applications.*.answers' => ['sometimes', 'array', 'max:300'],
            'plan.*.rounds.*.applications.*.answers.*.question' => ['required', 'integer', 'min:0'],
            'plan.*.rounds.*.applications.*.answers.*.response' => ['nullable', 'string', 'max:65000'],
        ]);
        $plan = array_values($request->input('plan'));
        // Emails still going out from the last run are finished first (a new run would write over who was told)
        foreach (['notify', 'invite', 'suppliers', 'inspectors'] as $key) {
            abort_if(($import->progress[$key]['status'] ?? null) === 'sending', 422, 'Emails from this import are still being sent. Try again when they are done.');
        }
        // Start at review and later bring the applications in: a program without them belongs in a Set up import
        if ($import->mode !== 'existing' && ! in_array($import->stage, ['setup', ProgramImport::MIXED_STAGE], true)) {
            // (a program chosen to come in "set up only" is the exception: it is brought in as a new program)
            $empty = collect($plan)->first(fn ($item) => empty($item['setup_only']) && ! collect($item['rounds'] ?? [])->sum(fn ($r) => count($r['applications'] ?? [])));
            abort_if($empty, 422, 'Every program in a "Start at review" import needs its applicants. Bring programs without applicants in with "Set up a program".');
        }
        if ($import->mode === 'existing') {
            // Adding to a program you have: only that program, and one the organization runs
            $this->authorizeProgram($import->program_id);
            // Bringing an existing program on (disbursement, monitoring): its awardees' milestones, payments and checkpoints
            abort_if(ExistingProgramState::addsFunding($import->stage) && collect($plan[0]['rounds'] ?? [])->isNotEmpty(), 422, 'An import that adds milestones to a program you have cannot add rounds as well.');
            // Adding to a program you have brings new rounds (and their fields) only, after the program's own, up to 10 in all
            $have = \App\Models\Programs\Rounds\ProgramRound::where('program_id', $import->program_id)->count();
            abort_if($have + count($plan[0]['rounds'] ?? []) > 10, 422, "This program already has {$have} rounds; a program can have at most 10.");
            $plan = [array_merge($plan[0], ['program_id' => $import->program_id])];
        }

        $previous = $import->progress['results'] ?? [];
        $import->forceFill([
            'plan' => $plan,
            'status' => 'importing',
            'step' => 'import',
            'started_at' => $import->started_at ?? now(),
            'finished_at' => null,
            'progress' => ['total' => count($plan),
                // This run's start, and how many were already created then, so the page can tell the time left accurately
                'run_started_at' => now()->toIso8601String(), 'run_from' => collect($previous)->where('status', 'created')->count(),
                'copies' => collect($plan)->filter(fn ($item) => ! empty($item['copy']))->count(), 'done' => collect($previous)->where('status', 'created')->count(), 'created' => collect($previous)->where('status', 'created')->count(), 'failed' => 0,
                // keep only what was created; failed ones are tried again
                'results' => collect($previous)->where('status', 'created')->all(),
                // who was already told stays told
                'notify' => $import->progress['notify'] ?? null,
                'invite' => $import->progress['invite'] ?? null,
                'suppliers' => $import->progress['suppliers'] ?? null,
                'inspectors' => $import->progress['inspectors'] ?? null],
        ])->save();

        config('queue.default') === 'sync'
            ? RunProgramImportJob::dispatchAfterResponse($import->id)
            : RunProgramImportJob::dispatch($import->id);

        return ApiResponseResource::success('Import started.', $import->load(['program:id,program_title', 'user:id,first_name,last_name'])->toView());
    }

    /**
     * Let applicants know (after step 5, when the person chooses to): emails each imported applicant once, so they
     * can sign in and follow their application. A new account gets a password; nobody is told twice.
     */
    public function notifyApplicants(ProgramImport $import)
    {
        $this->authorizeImport($import);
        abort_unless(in_array($import->status, ['completed', 'partial'], true), 422, 'Applicants can be told once the import has created their applications.');
        abort_if(($import->progress['notify']['status'] ?? null) === 'sending', 422, 'The emails are already being sent.');
        $pending = $import->applicationsToNotify();
        abort_if(! $pending, 422, 'Every applicant in this import has already been told.');

        $progress = $import->progress;
        $progress['notify'] = [
            'status' => 'sending',
            'total' => ProgramImport::peopleIn($pending),
            'sent' => 0,
            'sent_ids' => $progress['notify']['sent_ids'] ?? [],
            'started_at' => now()->toIso8601String(),
            'by' => auth()->id(),
        ];
        $import->forceFill(['progress' => $progress])->save();

        config('queue.default') === 'sync'
            ? NotifyImportedApplicantsJob::dispatchAfterResponse($import->id)
            : NotifyImportedApplicantsJob::dispatch($import->id);

        return ApiResponseResource::success('Sending the emails.', $import->load(['program:id,program_title', 'user:id,first_name,last_name'])->toView(false));
    }

    /**
     * Tell site visit officers (Start at monitoring & evaluation, after step 5, when the person chooses to): each officer
     * with a scheduled visit is told about it, as assigning a visit in the M&E tab tells them; someone the import made
     * new to Tujitume gets their invitation first. Nobody is told twice.
     */
    public function notifyInspectors(ProgramImport $import)
    {
        $this->authorizeImport($import);
        abort_unless(in_array($import->status, ['completed', 'partial'], true), 422, 'Officers can be told once the import has made their site visits.');
        abort_if(($import->progress['inspectors']['status'] ?? null) === 'sending', 422, 'The emails are already being sent.');
        $pending = $import->siteVisitsToTell();
        abort_if(! $pending, 422, 'Every site visit officer in this import has already been told.');

        $progress = $import->progress;
        $progress['inspectors'] = [
            'status' => 'sending',
            'total' => ProgramImport::officersAmong($pending),
            'sent' => 0,
            'sent_ids' => $progress['inspectors']['sent_ids'] ?? [],
            'started_at' => now()->toIso8601String(),
            'by' => auth()->id(),
        ];
        $import->forceFill(['progress' => $progress])->save();

        config('queue.default') === 'sync'
            ? \App\Jobs\NotifyImportedInspectorsJob::dispatchAfterResponse($import->id)
            : \App\Jobs\NotifyImportedInspectorsJob::dispatch($import->id);

        return ApiResponseResource::success('Telling the site visit officers.', $import->load(['program:id,program_title', 'user:id,first_name,last_name'])->toView(false));
    }

    /**
     * Tell suppliers (Start at disbursement, after step 5, when the person chooses to): the emails the app sends when
     * a supplier is added by hand: a welcome to a supplier new to the list, and each milestone they were added to.
     * Nobody is told twice.
     */
    public function notifySuppliers(ProgramImport $import)
    {
        $this->authorizeImport($import);
        abort_unless(in_array($import->status, ['completed', 'partial'], true), 422, 'Suppliers can be told once the import has set up their milestones.');
        abort_if(($import->progress['suppliers']['status'] ?? null) === 'sending', 422, 'The emails are already being sent.');
        $pending = $import->supplierLinksToTell();
        abort_if(! $pending, 422, 'Every supplier in this import has already been told.');

        $progress = $import->progress;
        $progress['suppliers'] = [
            'status' => 'sending',
            'total' => ProgramImport::suppliersAmong($pending),
            'sent' => 0,
            'sent_ids' => $progress['suppliers']['sent_ids'] ?? [],
            'started_at' => now()->toIso8601String(),
            'by' => auth()->id(),
        ];
        $import->forceFill(['progress' => $progress])->save();

        config('queue.default') === 'sync'
            ? \App\Jobs\NotifyImportedSuppliersJob::dispatchAfterResponse($import->id)
            : \App\Jobs\NotifyImportedSuppliersJob::dispatch($import->id);

        return ApiResponseResource::success('Telling the suppliers.', $import->load(['program:id,program_title', 'user:id,first_name,last_name'])->toView(false));
    }

    /**
     * Invite Business Coaches (after step 5, when the person chooses to): a new Business Coach gets their
     * invitation to Tujitume; everyone is told which rounds they were asked to review. Nobody is asked twice.
     */
    public function inviteCoaches(ProgramImport $import)
    {
        $this->authorizeImport($import);
        abort_unless(in_array($import->status, ['completed', 'partial'], true), 422, 'Business Coaches can be invited once the import has added them.');
        abort_if(($import->progress['invite']['status'] ?? null) === 'sending', 422, 'The invitations are already being sent.');
        $pending = $import->reviewersToInvite();
        abort_if(! $pending, 422, 'Every Business Coach in this import has already been invited.');

        $progress = $import->progress;
        $progress['invite'] = [
            'status' => 'sending',
            'total' => ProgramImport::peopleAmongReviewers($pending),
            'sent' => 0,
            'sent_ids' => $progress['invite']['sent_ids'] ?? [],
            'started_at' => now()->toIso8601String(),
            'by' => auth()->id(),
        ];
        $import->forceFill(['progress' => $progress])->save();

        config('queue.default') === 'sync'
            ? InviteImportedCoachesJob::dispatchAfterResponse($import->id)
            : InviteImportedCoachesJob::dispatch($import->id);

        return ApiResponseResource::success('Sending the invitations.', $import->load(['program:id,program_title', 'user:id,first_name,last_name'])->toView(false));
    }

    /**
     * Who the people of a Business Coaches list are (for the review page, while the person fixes): for each email
     * and kind (team member or Business Coach), whether it is on the team, a service provider, one of the
     * organization's coaches, someone new, or another kind of account that cannot be used here.
     */
    public function people(Request $request, ProgramImport $import)
    {
        $this->authorizeImport($import);
        $data = $request->validate([
            'people' => ['required', 'array', 'max:500'],
            'people.*.email' => ['required', 'string', 'max:255'],
            'people.*.kind' => ['required', Rule::in(['team', 'coach'])],
        ]);

        return ApiResponseResource::success('People checked.', \App\Service\Program\ImportCoachAccount::describe($data['people'], (int) $import->organization_id));
    }

    /**
     * POST /programs/imports/{import}/suppliers  { emails: [] }
     * Which milestone suppliers of the import are already in the owner's supplier list (same email): those come in as
     * they are, paid the way the list says, so the file need not repeat their payment details. Also whether each one
     * uses Tujitume. Only what the review page shows: name, payment method and a short account line.
     */
    public function suppliers(Request $request, ProgramImport $import)
    {
        $this->authorizeImport($import);
        $data = $request->validate(['emails' => ['required', 'array', 'max:2000'], 'emails.*' => ['required', 'string', 'max:255']]);
        $emails = collect($data['emails'])->map(fn ($e) => mb_strtolower(trim($e)))->filter()->unique()->values();
        $owner = (int) $import->user_id;

        $known = \App\Models\Programs\SupplierDirectory::where('added_by', $owner)->whereIn(\DB::raw('LOWER(email)'), $emails)->get()
            ->keyBy(fn ($s) => mb_strtolower($s->email));
        $accounts = \App\Models\Auth\User::whereIn(\DB::raw('LOWER(email)'), $emails)->pluck('email')->map(fn ($e) => mb_strtolower($e))->flip();
        $account = fn ($s) => match ($s->payment_method) {
            'mpesa_paybill' => trim(($s->mpesa_paybill_number ?? '').' / '.($s->mpesa_paybill_account ?? ''), ' /'),
            'mpesa_till' => (string) $s->mpesa_till_number,
            'mpesa_mobile' => (string) $s->lipr_mobile_number,
            'mpesa_lipr' => filled($s->lipr_wallet) ? 'wallet' : '',
            'bank_transfer' => trim(($s->bank_name ?? '').' '.($s->bank_account_number ?? '')),
            default => '',
        };

        return ApiResponseResource::success('Suppliers checked.', $emails->mapWithKeys(fn ($e) => [$e => $known->has($e)
            ? ['in_list' => true, 'name' => $known[$e]->legal_name, 'payment_method' => $known[$e]->payment_method, 'account' => $account($known[$e]), 'active' => (bool) $known[$e]->is_active, 'on_tujitume' => $accounts->has($e) || (bool) $known[$e]->user_id]
            : ['in_list' => false, 'on_tujitume' => $accounts->has($e)]])->all());
    }

    /**
     * POST /programs/imports/{import}/ai-review
     * Start at review and later, after step 5, when the person chooses to: hands the imported applications of rounds
     * set to AI review to the app's own AI review queue (AiReviewDispatcher), as a submission does. The queue reviews
     * them one by one at its own pace; the round's review area shows them and can pause or continue the AI.
     */
    public function aiReview(ProgramImport $import, \App\Service\AiReview\AiReviewDispatcher $dispatcher)
    {
        $this->authorizeImport($import);
        abort_unless(in_array($import->status, ['completed', 'partial'], true), 422, 'AI review can start once the import has created the applications.');
        abort_unless(config('ai_review.enabled'), 422, 'AI review is not turned on for this server.');
        $ids = $import->applicationsForAi();
        abort_if(! $ids, 422, 'No imported application is waiting for AI review.');

        $queued = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            foreach (\App\Models\Programs\ProgramApplication::whereIn('id', $chunk)->with('currentRound')->get() as $application) {
                try {
                    if ($dispatcher->dispatchFor($application)) $queued++;
                } catch (\Throwable $e) {
                    \Log::warning('Program import: an application could not be queued for AI review', ['import' => $import->id, 'application' => $application->id, 'error' => $e->getMessage()]);
                }
            }
        }

        $progress = $import->progress;
        $progress['ai'] = ['status' => 'sent', 'sent' => ($progress['ai']['sent'] ?? 0) + $queued, 'total' => $queued, 'sent_at' => now()->toIso8601String(), 'by' => auth()->id()];
        $import->forceFill(['progress' => $progress])->save();

        return ApiResponseResource::success('AI review started.', $import->load(['program:id,program_title', 'user:id,first_name,last_name'])->toView(false));
    }

    public function destroy(ProgramImport $import)
    {
        $this->authorizeImport($import);
        abort_if($import->status === 'importing', 422, 'This import is running. Wait until it has finished.');
        $this->forgetFile($import);
        Storage::disk(self::DISK)->deleteDirectory("program-imports/{$import->organization_id}/{$import->id}");
        $import->delete();

        return ApiResponseResource::success('Import deleted.');
    }

    /**
     * An import still "importing" that nothing is working on is started again: without a queue worker (sync) that is
     * how its next part runs; with one, a run stopped half way (nothing saved for a minute) is picked up again. Programs already created are skipped, so nothing is made twice; the
     * job's own lock keeps a second run from starting while one is still working.
     */
    private function resumeIfStalled(ProgramImport $import): void
    {
        // Without a queue worker (sync) the next part runs on the next check; with one, only a stopped run is restarted
        $quiet = config('queue.default') === 'sync' ? now()->subSeconds(2) : now()->subMinute();
        if ($import->status !== 'importing' || $import->updated_at?->gt($quiet)) return;
        if (! Cache::lock("program-import:{$import->id}", 1)->get()) return;
        Cache::lock("program-import:{$import->id}")->forceRelease();
        $import->touch();
        config('queue.default') === 'sync'
            ? RunProgramImportJob::dispatchAfterResponse($import->id)
            : RunProgramImportJob::dispatch($import->id);
    }

    /**
     * The emails sent after an import (applicants, Business Coaches, suppliers, site visit officers) go out in short
     * runs. Without a queue worker a run that used its time is not handed on, and one that was stopped stays on
     * "sending": the page's next progress check carries it on (each person told saves the import, so quiet means stopped).
     */
    private function resumeSendsIfStalled(ProgramImport $import): void
    {
        $jobs = [
            'notify' => [NotifyImportedApplicantsJob::class, 'program-import-notify'],
            'invite' => [\App\Jobs\InviteImportedCoachesJob::class, 'program-import-invite'],
            'suppliers' => [\App\Jobs\NotifyImportedSuppliersJob::class, 'program-import-suppliers'],
            'inspectors' => [\App\Jobs\NotifyImportedInspectorsJob::class, 'program-import-inspectors'],
        ];
        $quiet = config('queue.default') === 'sync' ? now()->subSeconds(5) : now()->subMinutes(2);
        if ($import->updated_at?->gt($quiet)) return;
        foreach ($jobs as $key => [$job, $lockName]) {
            if (($import->progress[$key]['status'] ?? null) !== 'sending') continue;
            // A run still holding its lock is working: leave it
            $lock = Cache::lock("{$lockName}:{$import->id}", 1);
            if (! $lock->get()) continue;
            $lock->release();
            $import->touch();
            config('queue.default') === 'sync' ? $job::dispatchAfterResponse($import->id) : $job::dispatch($import->id);
        }
    }

    private function forgetFile(ProgramImport $import): void
    {
        if ($import->file_path) Storage::disk(self::DISK)->delete($import->file_path);
        $import->file_path = null;
        $import->file_name = null;
        $import->file_size = null;
        $import->data = null;
    }
}
