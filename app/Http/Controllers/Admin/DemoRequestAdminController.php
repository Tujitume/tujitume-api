<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\DemoRequest;
use App\Models\Organizations\Workspace;
use App\Models\ProgramRegistrationInvite;
use App\Models\Programs\Program;
use App\Service\DemoRequests\DemoAccountHandoff;
use App\Service\DemoRequests\DemoRequestMailer;
use App\Service\DemoRequests\ProposalQuote;
use App\Service\DemoRequests\DemoRequestService;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\RecipientUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin › Demo Requests: review landing-page requests (approve a demo → 7-day account, reject with a reason,
 * answer with an editable proposal), follow trial accounts (extend, end now, resend sign-in link, give full
 * access), edit the proposal templates, and send one-time sign-up links to any email address.
 */
class DemoRequestAdminController extends Controller
{
    /** Tab → statuses */
    public const TABS = [
        'pending' => [DemoRequest::STATUS_PENDING],
        // A registered request's demo is already deleted (the full account replaced it): it sits with Full access
        'trials' => [DemoRequest::STATUS_TRIAL_ACTIVE, DemoRequest::STATUS_FULL_ACCESS_REQUESTED],
        'answered' => [DemoRequest::STATUS_PROPOSAL_SENT],
        'converted' => [DemoRequest::STATUS_REGISTERED, DemoRequest::STATUS_CONVERTED],
        'closed' => [DemoRequest::STATUS_REJECTED, DemoRequest::STATUS_EXPIRED, DemoRequest::STATUS_CLOSED],
        'all' => [],
    ];

    public function __construct(private DemoRequestService $requests)
    {
        parent::__construct();
    }

    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');
        abort_unless(array_key_exists($status, self::TABS), 404);
        $type = $request->query('type');
        $search = trim((string) $request->query('q', ''));

        $demoRequests = DemoRequest::query()
            ->with('programIndustry')
            ->when(self::TABS[$status] !== [], fn ($q) => $q->whereIn('status', self::TABS[$status]))
            ->when(in_array($type, DemoRequest::REQUEST_TYPES, true), fn ($q) => $q->where('request_type', $type))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('org', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $counts = collect(self::TABS)->map(fn ($statuses) => $statuses === []
            ? DemoRequest::count()
            : DemoRequest::whereIn('status', $statuses)->count());

        return view('admin.demo_requests.index', compact('demoRequests', 'status', 'counts', 'type', 'search'));
    }

    public function show(DemoRequest $demoRequest)
    {
        $demoRequest->load('programIndustry', 'reviewer');

        $trial = null;
        if ($demoRequest->trial_organization_id && $demoRequest->trial_closed_at === null) {
            $workspace = Workspace::where('organization_id', $demoRequest->trial_organization_id)->first();
            $user = User::find($demoRequest->trial_user_id);
            $trial = [
                'user' => $user,
                'password_set' => $user && $user->password !== null,
                'workspace' => $workspace,
                'workspace_url' => $workspace ? (RecipientUrl::workspaceBase($workspace) ?? RecipientUrl::main()) : null,
                'programs' => Program::where('organization_id', $demoRequest->trial_organization_id)->count(),
                'people' => User::where('organization_id', $demoRequest->trial_organization_id)->count(),
            ];
        }

        $invites = ProgramRegistrationInvite::where('demo_request_id', $demoRequest->id)
            ->orWhereRaw('LOWER(email) = ?', [strtolower($demoRequest->email)])
            ->orderByDesc('id')->get();
        $convertedUser = $demoRequest->converted_user_id ? User::find($demoRequest->converted_user_id) : null;
        $template = DemoRequestMailer::templateFor($demoRequest);
        $quotes = $demoRequest->quotes()->with('admin')->get();
        $quote = $quotes->first();
        $quoteTotals = $quote ? ProposalQuote::calculate($quote) : null;
        $quoteDates = $quote ? ProposalQuote::milestones($demoRequest, $quote) : null;
        $quoteSummary = $quote ? ProposalQuote::summary($quote) : null;

        return view('admin.demo_requests.show', compact('demoRequest', 'trial', 'invites', 'convertedUser', 'template',
            'quotes', 'quote', 'quoteTotals', 'quoteDates', 'quoteSummary'));
    }

    public function approve(DemoRequest $demoRequest)
    {
        return $this->act(fn () => $this->requests->approve($demoRequest, $this->adminId()),
            'Approved. The 7-day demo account was created and '.$demoRequest->email.' was emailed the sign-in link.');
    }

    public function reject(Request $request, DemoRequest $demoRequest)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);

        return $this->act(fn () => $this->requests->reject($demoRequest, $data['rejection_reason'], $this->adminId()),
            'Rejected. The reason was emailed to '.$demoRequest->email.'.');
    }

    public function proposal(Request $request, DemoRequest $demoRequest)
    {
        $data = $request->validate([
            'proposal_subject' => ['required', 'string', 'max:255'],
            'proposal_body' => ['required', 'string', 'max:20000'],
        ]);

        return $this->act(function () use ($demoRequest, $data) {
            if (! $this->requests->sendProposal($demoRequest, $data['proposal_subject'], $data['proposal_body'], $this->adminId())) {
                throw new \RuntimeException('The email could not be sent. It was saved; try again.');
            }
        }, 'Sent to '.$demoRequest->email.'.');
    }

    /** Save a new version of the proposal terms (the internal pricing calculator). */
    public function quote(Request $request, DemoRequest $demoRequest)
    {
        $data = $request->validate(ProposalQuote::rules());
        ProposalQuote::save($demoRequest, $data, $this->adminId());

        return back()->with('success', 'Proposal terms saved. Use "Put the terms in the message" to add them to the email.');
    }

    public function resendAccess(DemoRequest $demoRequest)
    {
        return $this->act(function () use ($demoRequest) {
            if (! $this->requests->resendAccess($demoRequest)) {
                throw new \RuntimeException('The password is already set (or the demo has ended), so there is no sign-in link to resend.');
            }
        }, 'A new sign-in link was emailed to '.$demoRequest->email.'.');
    }

    public function extend(Request $request, DemoRequest $demoRequest)
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:30']]);

        return $this->act(fn () => $this->requests->extendTrial($demoRequest, (int) $data['days']),
            'The demo was extended by '.$data['days'].' day(s).');
    }

    public function endTrial(DemoRequest $demoRequest)
    {
        return $this->act(fn () => $this->requests->endTrialNow($demoRequest),
            'The demo account and its data were deleted.');
    }

    /** Opens the demo account in a new tab, signed in as its owner (only ever a demo account) */
    public function openAccount(DemoRequest $demoRequest, DemoAccountHandoff $handoff)
    {
        try {
            return redirect()->away($handoff->issue($demoRequest, $this->adminId()));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function fullAccess(DemoRequest $demoRequest)
    {
        return $this->act(fn () => $this->requests->grantFullAccess($demoRequest, $this->adminId()),
            'A one-time sign-up link was emailed to '.$demoRequest->email.'.');
    }

    public function close(DemoRequest $demoRequest)
    {
        return $this->act(function () use ($demoRequest) {
            if ($demoRequest->hasTrial()) {
                throw new \RuntimeException('End the demo account first.');
            }
            $demoRequest->update(['status' => DemoRequest::STATUS_CLOSED]);
        }, 'The request was closed.');
    }

    public function notes(Request $request, DemoRequest $demoRequest)
    {
        $data = $request->validate(['admin_notes' => ['nullable', 'string', 'max:5000']]);
        $demoRequest->update($data);

        return back()->with('success', 'Notes saved.');
    }

    public function templates()
    {
        $templates = collect([DemoRequest::TYPE_PROPOSAL, DemoRequest::TYPE_PILOT, DemoRequest::TYPE_INFO])
            ->mapWithKeys(fn ($type) => [$type => DemoRequestMailer::rawTemplate($type)]);

        return view('admin.demo_requests.templates', compact('templates'));
    }

    public function saveTemplate(Request $request, string $type)
    {
        abort_unless(in_array($type, [DemoRequest::TYPE_PROPOSAL, DemoRequest::TYPE_PILOT, DemoRequest::TYPE_INFO], true), 404);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
        ]);
        DemoRequestMailer::saveTemplate($type, $data['subject'], $data['body']);

        return back()->with('success', 'Template saved.');
    }

    public function invites()
    {
        $invites = ProgramRegistrationInvite::orderByDesc('id')->paginate(25);

        return view('admin.demo_requests.invites', compact('invites'));
    }

    public function createInvite(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);
        if (User::whereRaw('LOWER(email) = ?', [strtolower($data['email'])])->exists()) {
            return back()->with('error', 'An account with '.$data['email'].' already exists.');
        }

        return $this->act(fn () => $this->requests->inviteEmail($data['email'], $data['name'] ?? null, $this->adminId()),
            'A one-time sign-up link was emailed to '.$data['email'].'.');
    }

    public function revokeInvite(ProgramRegistrationInvite $invite)
    {
        if ($invite->state() === 'open') {
            $invite->update(['revoked_at' => now()]);
        }

        return back()->with('success', 'The link no longer works.');
    }

    private function act(callable $action, string $success)
    {
        try {
            $action();

            return back()->with('success', $success);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['admin_id' => $this->adminId()]);

            return back()->with('error', 'Something went wrong: '.$e->getMessage());
        }
    }

    private function adminId(): int
    {
        return (int) Auth::guard('admin')->id();
    }
}
