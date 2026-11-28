<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalOffer;
use App\Models\Programs\Program;
use App\Models\Services\ServiceProposal;
use App\Models\Services\Services;
use App\Service\Misc\ErrorLogService;
use App\Support\ListQuery;
use App\Support\ApiContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Service requests: a service provider shares one of their services with someone they could work for (a business,
 * a program or a capital offer) and says how they would help. The owner of that business / program / offer answers.
 *
 *   POST /services/proposals                 provider sends {service_id, target_type, target_id, message, proposed_price?}
 *   GET  /services/proposals/received        what was sent to me (server list: search, filters, pages, export)
 *   GET  /services/proposals/sent            what I sent (providers)
 *   GET  /services/proposals/{proposal}      one request, for its provider or its recipient
 *   POST /services/proposals/{proposal}/decide    recipient: {decision: accept|decline, note?}
 *   POST /services/proposals/{proposal}/withdraw  provider, while pending
 *
 * A program organization's team reads and answers the owner's requests (the owner is the recipient).
 */
class ServiceProposalController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_id' => 'required|integer',
            'target_type' => 'required|in:'.implode(',', ServiceProposal::TARGETS),
            'target_id' => 'required|integer',
            'message' => 'required|string|min:10|max:2000',
            'proposed_price' => 'nullable|numeric|min:0',
        ]);
        $provider = $request->user();

        if ((int) $provider->user_type_id !== 3) {
            return $this->refuse('Only service providers can share a service.', 403);
        }
        $service = Services::query()->whereKey($data['service_id'])->where('user_id', $provider->id)->first();
        if (! $service) {
            return $this->refuse('Choose one of your own services.', 422, 'service_id');
        }
        if (! $service->active) {
            return $this->refuse('Turn this service on before sharing it, so they can book it.', 422, 'service_id');
        }

        [$recipientId, $targetName] = $this->target($data['target_type'], (int) $data['target_id']);
        if (! $recipientId) {
            return $this->refuse('That business, program or offer was not found.', 404);
        }
        if ((int) $recipientId === (int) $provider->id) {
            return $this->refuse('You cannot send a request to yourself.', 422);
        }

        $open = ServiceProposal::query()
            ->where('service_id', $service->id)->where('target_type', $data['target_type'])->where('target_id', $data['target_id'])
            ->where('status', 'pending')->exists();
        if ($open) {
            return $this->refuse('You already shared this service with them; wait for their answer.', 409);
        }

        $proposal = ServiceProposal::create([
            'provider_id' => $provider->id,
            'service_id' => $service->id,
            'recipient_id' => $recipientId,
            'target_type' => $data['target_type'],
            'target_id' => $data['target_id'],
            'target_name' => $targetName,
            'message' => trim($data['message']),
            'proposed_price' => $data['proposed_price'] ?? null,
            'status' => 'pending',
        ]);

        $providerName = trim($provider->first_name.' '.$provider->last_name) ?: $provider->email;
        $this->tell(
            $recipientId, $provider->id,
            "Service request\n{$providerName} would like to work with {$targetName}: {$service->name}.",
            self::recipientLink($recipientId, $proposal->id)
        );

        return response()->json(['success' => true, 'message' => 'Request sent', 'data' => $this->present($proposal->fresh())], 201);
    }

    public function received(Request $request): JsonResponse
    {
        $base = ServiceProposal::query()->where(fn ($q) => $this->addressedTo($q, $request->user()));
        // ?target_type=listing&target_id=7: only the requests about one business / program / offer (its own page)
        if ($request->filled('target_type') && $request->filled('target_id')) {
            $base->where('target_type', (string) $request->query('target_type'))->where('target_id', (int) $request->query('target_id'));
        }

        return $this->listOf($request, $base);
    }

    public function sent(Request $request): JsonResponse
    {
        return $this->listOf($request, ServiceProposal::query()->where('provider_id', $request->user()->id));
    }

    public function show(Request $request, ServiceProposal $proposal): JsonResponse
    {
        if (! $this->canSee($request->user(), $proposal)) {
            return $this->refuse('Not found.', 404);
        }

        return response()->json(['success' => true, 'data' => $this->present($proposal)]);
    }

    public function decide(Request $request, ServiceProposal $proposal): JsonResponse
    {
        $data = $request->validate([
            'decision' => 'required|in:accept,decline',
            'note' => 'nullable|string|max:1000',
        ]);
        $user = $request->user();
        if (! $this->canAnswer($user, $proposal)) {
            return $this->refuse('Only the person it was sent to can answer.', 403);
        }
        if ($proposal->status !== 'pending') {
            return $this->refuse('This request was already answered.', 409);
        }

        $proposal->update([
            'status' => $data['decision'] === 'accept' ? 'accepted' : 'declined',
            'decision_note' => $data['note'] ?? null,
            'decided_at' => now(),
        ]);

        $who = trim($user->first_name.' '.$user->last_name) ?: $user->email;
        $text = $proposal->status === 'accepted'
            ? "Service request accepted\n{$who} accepted your request to work with {$proposal->target_name}. They can now book your service."
            : "Service request declined\n{$who} declined your request to work with {$proposal->target_name}.".($proposal->decision_note ? " Note: {$proposal->decision_note}" : '');
        $this->tell($proposal->provider_id, $user->id, $text, 'dashboard.serviceProvider.serviceRequests?tab=sent&request=@'.$proposal->id);

        return response()->json(['success' => true, 'message' => $proposal->status === 'accepted' ? 'Request accepted' : 'Request declined', 'data' => $this->present($proposal->fresh())]);
    }

    public function withdraw(Request $request, ServiceProposal $proposal): JsonResponse
    {
        if ((int) $proposal->provider_id !== (int) $request->user()->id) {
            return $this->refuse('Only the person who sent it can withdraw it.', 403);
        }
        if ($proposal->status !== 'pending') {
            return $this->refuse('This request was already answered.', 409);
        }
        $proposal->update(['status' => 'withdrawn', 'decided_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Request withdrawn', 'data' => $this->present($proposal->fresh())]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Where the recipient answers: the Service requests page of their own dashboard, opened on this request */
    public static function recipientLink(int $recipientId, int $proposalId): string
    {
        $home = match ((int) User::whereKey($recipientId)->value('user_type_id')) {
            1 => 'dashboard.entrepreneur', 2 => 'dashboard.investor', 4 => 'dashboard.programOrg',
            5 => 'dashboard.capitalOrg', default => 'dashboard.serviceProvider',
        };

        return $home.'.serviceRequests?request=@'.$proposalId;
    }

    /** [recipient user id, readable name] of what the request is aimed at, or [null, null] */
    private function target(string $type, int $id): array
    {
        return match ($type) {
            'listing' => ($l = Listing::query()->whereKey($id)->first(['id', 'user_id', 'name'])) ? [(int) $l->user_id, (string) $l->name] : [null, null],
            // ISSUE-43: a program belongs to its organization, so its owner receives it (the team sees and answers it)
            'program' => ($p = Program::query()->whereKey($id)->where('visible', true)->first(['id', 'user_id', 'program_title', 'organization_id']))
                ? [self::programRecipient($p), (string) $p->program_title] : [null, null],
            'capital_offer' => ($o = CapitalOffer::query()->whereKey($id)->first(['id', 'user_id', 'offer_title'])) ? [(int) $o->user_id, (string) $o->offer_title] : [null, null],
            default => [null, null],
        };
    }

    /** Program organization team members act for the owner; everyone else for themselves */
    private function ownerId(User $user): int
    {
        return (int) $user->user_type_id === 4 ? (int) $user->organizationOwnerId() : (int) $user->id;
    }

    /** The organization owner of a program (its creator when it has no organization) */
    public static function programRecipient(Program $program): int
    {
        $orgId = $program->organizationId();
        $owner = $orgId ? \App\Models\Organizations\Organization::whereKey($orgId)->value('owner_user_id') : null;

        return (int) ($owner ?: $program->user_id);
    }

    /** Requests for this person: sent to them (or their organization owner), and, for a program team, any about the organization's programs */
    private function addressedTo($query, User $user): void
    {
        $query->where('recipient_id', $this->ownerId($user));
        if ((int) $user->user_type_id === 4) {
            $query->orWhere(fn ($q) => $q->where('target_type', 'program')->whereIn('target_id', Program::ofOrganization($user)->select('id')));
        }
    }

    /** Who answers: the recipient (or, for a program, whoever may run it: its organization's owner and program team) */
    private function canAnswer(User $user, ServiceProposal $proposal): bool
    {
        if ($proposal->target_type === 'program') {
            return \App\Service\Program\ProgramAccess::owns(Program::find($proposal->target_id));
        }

        return (int) $proposal->recipient_id === $this->ownerId($user);
    }

    private function canSee(User $user, ServiceProposal $proposal): bool
    {
        return (int) $proposal->provider_id === (int) $user->id || (int) $proposal->recipient_id === $this->ownerId($user)
            || ($proposal->target_type === 'program' && Program::ofOrganization($user)->whereKey($proposal->target_id)->exists());
    }

    private function listOf(Request $request, $base): JsonResponse
    {
        $list = ListQuery::for($base->with(['provider:id,first_name,last_name,email,image', 'recipient:id,first_name,last_name,email,image', 'service']), $request)
            ->search(['message', 'target_name'])
            ->options('status', 'status', label: 'app:status', values: ServiceProposal::STATUSES, fallback: 'Status')
            ->options('target', 'target_type', label: 'app:serviceRequestTarget', values: ServiceProposal::TARGETS, fallback: 'Sent about')
            ->dateRange('when', 'created_at', label: 'app:date', fallback: 'Date')
            ->sortable(['when' => 'created_at', 'status' => 'status'], default: '-when');

        [$rows, $page] = $list->fetch();
        $data = [
            'items' => $rows->map(fn (ServiceProposal $p) => $this->present($p))->values(),
            'filter_schema' => $list->filterSchema(),
            'stats' => [
                'pending' => (clone $base)->where('status', 'pending')->count(),
                'accepted' => (clone $base)->where('status', 'accepted')->count(),
                'total' => (clone $base)->count(),
            ],
        ];
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function person(?User $u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => trim($u->first_name.' '.$u->last_name) ?: $u->email, 'image' => $u->image] : null;
    }

    private function present(ServiceProposal $p): array
    {
        $p->loadMissing(['provider', 'recipient', 'service']);
        $s = $p->service;

        return [
            'id' => $p->id,
            'status' => $p->status,
            'message' => $p->message,
            'proposed_price' => $p->proposed_price,
            'decision_note' => $p->decision_note,
            'decided_at' => $p->decided_at?->toISOString(),
            'created_at' => $p->created_at?->toISOString(),
            'target' => ['type' => $p->target_type, 'id' => $p->target_id, 'name' => $p->target_name],
            'provider' => $this->person($p->provider),
            'recipient' => $this->person($p->recipient),
            'service' => $s ? [
                'id' => $s->id, 'name' => $s->name, 'price' => $s->price, 'active' => (bool) $s->active,
                'category' => $s->category ?? null, 'image' => $s->image ?? null, 'description' => $s->details ?? null,
            ] : null,
        ];
    }

    private function tell(int $to, int $from, string $text, string $link): void
    {
        try {
            $this->notification->create($to, $from, $text, $link, 'service');
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['notify' => 'service_proposal', 'to' => $to]);
        }
    }

    private function refuse(string $message, int $status, ?string $field = null): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'errors' => $field ? [$field => [$message]] : null,
        ]), $status);
    }
}
