<?php

namespace App\Http\Controllers\Misc;

use App\Events\ChatNotification;
use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Communication\Messages;
use App\Models\Services\ServiceMessages;
use App\Models\Services\Services;
use App\Service\Misc\ErrorLogService;
use App\Service\Notification\EmailBrand;
use App\Service\Notification\EmailLink;
use App\Service\Notification\EmailService;
use App\Service\Validation\SpamWordChecker;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class MessageController extends Controller
{
    private const PAGE_SIZE = 20;

    /**
     * Real-time push is best effort. The message is already saved, so an
     * unreachable websocket server must not fail the request.
     */
    private function broadcastChat(array $payload, $toId): void
    {
        try {
            // Who wrote it, so the other person's open conversation refreshes at once
            if (Auth::check()) {
                $payload['from_id'] = $this->actingId(Auth::user());
            }
            event(new ChatNotification($payload, (int) $toId));
        } catch (Throwable $e) {
            Log::warning('Chat broadcast failed: ' . $e->getMessage());
        }
    }

    /**
     * The id a person reads and writes messages under (the same one in store, index, thread and mark-read).
     * A capital organization's editors and admins share their owner's inbox: they write as the owner, every message keeps
     * who really wrote it in sent_by_id, and they read the owner's conversations. Everyone else, including a program
     * organization's team members (each has their own dialog since 2026-11-14), acts as themselves.
     */
    /** The inbox a person reads: their own, or their capital owner's for editors/admins (used by routes/channels.php) */
    public static function inboxOwnerId(User $user): int
    {
        return (new self())->actingId($user);
    }

    /**
     * POST /messages/typing {to_id}: tell the other person, live, that you are writing to them. The app sends it at most
     * every few seconds while you type; nothing is stored.
     */
    public function typing(Request $request)
    {
        $data = $request->validate(['to_id' => 'required|integer|exists:users,id']);
        $user = Auth::user();
        $from = $this->actingId($user);
        if ((int) $data['to_id'] === $from) {
            return response()->json(['ok' => false], 422);
        }
        try {
            event(new \App\Events\ChatSignal('Typing', (int) $data['to_id'], [
                'from_id' => $from,
                'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
            ]));
        } catch (Throwable $e) {
            Log::warning('Typing broadcast failed: '.$e->getMessage());
        }

        return response()->json(['ok' => true]);
    }

    private function actingId(User $user): int
    {
        if ((int) $user->user_type_id === 5) { // capital provider (team members share the owner's inbox)
            $role = $user->capital_profile?->role?->name;
            if (in_array($role, ['editor', 'admin', 'super_admin'], true)) {
                return (int) ($user->capital_profile?->capital_owner_id ?: $user->id);
            }
        }

        return (int) $user->id;
    }

    /** A team member who shares the owner's inbox cannot hold a conversation with that inbox itself */
    private function sharesInboxWith(User $user, int $actingId, int $partnerId): bool
    {
        return $actingId !== (int) $user->id && $partnerId === $actingId;
    }

    private const SHARED_INBOX_MESSAGE = 'You share this inbox with your organization owner, so you cannot open a chat with them here.';

    /**
     * A page of one conversation, newest first. `before` is the id of the oldest
     * message the client already has. `$viewerId` is the person reading (for "is_me"); `$authId` the inbox they read.
     */
    private function threadPage(int $authId, int $partnerId, ?int $before, int $limit, ?int $viewerId = null): array
    {
        $viewerId ??= $authId;
        $rows = Messages::where(function ($q) use ($authId, $partnerId) {
            $q->where(function ($q) use ($authId, $partnerId) {
                $q->where('from_id', $authId)->where('to_id', $partnerId);
            })->orWhere(function ($q) use ($authId, $partnerId) {
                $q->where('from_id', $partnerId)->where('to_id', $authId);
            });
        })
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;

        // Who really wrote each message (it is stored under the organization owner when a team member sends it)
        $authors = User::whereIn('id', $rows->take($limit)->pluck('sent_by_id')->filter()->unique())
            ->get(['id', 'first_name', 'last_name', 'display_name', 'image'])
            ->keyBy('id');

        $messages = $rows->take($limit)->map(function ($msg) use ($authId, $viewerId, $authors) {
            $msg->sender = $msg->from_id === $authId ? 'me' : '';

            $author = $authors->get($msg->sent_by_id);
            $msg->author = $author ? [
                'id'    => $author->id,
                'name'  => $author->display_name ?: trim($author->first_name . ' ' . $author->last_name),
                'image' => $author->image,
                'is_me' => (int) $author->id === $viewerId,
            ] : null;

            return $msg;
        })->values();

        return ['messages' => $messages, 'has_more' => $hasMore];
    }

    /**
     * Older messages for one conversation: GET messages/thread/{partnerId}?before=<id>&limit=20
     */
    public function thread(Request $request, $partnerId)
    {
        try {
            $validated = $request->validate([
                'before' => 'nullable|integer|min:1',
                'limit'  => 'nullable|integer|min:1|max:50',
            ]);

            $user = Auth::user();
            $actingId = $this->actingId($user);
            if ($this->sharesInboxWith($user, $actingId, (int) $partnerId)) {
                return response()->json(['message' => self::SHARED_INBOX_MESSAGE], 422);
            }

            return response()->json(
                $this->threadPage(
                    $actingId,
                    (int) $partnerId,
                    isset($validated['before']) ? (int) $validated['before'] : null,
                    (int) ($validated['limit'] ?? self::PAGE_SIZE),
                    (int) $user->id
                ),
                200
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function protection(Request $request, SpamWordChecker $spam)
    {
        $validated = $request->validate(['text' => 'required|string|max:2000']);
        $text = $validated['text'];
        preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}/', $text, $emailMatches);
        preg_match_all('/(\\+\\d{1,3}[-.\\s]?)?\\(?\\d{3}\\)?[-.\\s]?\\d{3}[-.\\s]?\\d{4}/', $text, $phoneMatches);
        $checkText = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}|(\\+\\d{1,3}[-.\\s]?)?\\(?\\d{3}\\)?[-.\\s]?\\d{3}[-.\\s]?\\d{4}/', ' ', $text);
        $censoredCheck = $spam->censorText($checkText);
        return response()->json([
            'emails' => $emailMatches[0],
            'phones' => $phoneMatches[0],
            'abusiveWordsFound' => $censoredCheck !== $checkText,
            'abusiveCensored' => $spam->censorText($text),
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try{
            $viewerId = (int) Auth::id();
            // A capital editor/admin reads the owner's shared inbox (see actingId)
            $authId = $this->actingId(Auth::user());

            // Step 1: Get all unique chat partners (anyone who sent or received messages with me)
            $partnerIds = Messages::where(function ($q) use ($authId) {
                $q->where('to_id', $authId)
                    ->orWhere('from_id', $authId);
            })
                ->selectRaw('CASE WHEN from_id = ? THEN to_id ELSE from_id END as partner_id', [$authId])
                ->distinct()
                ->pluck('partner_id');

            // Step 2: One entry per chat partner: profile, unread count and only the
            // latest page of messages. Older messages load through thread().
            $results = $partnerIds->map(function ($partnerId) use ($authId, $viewerId) {
                $partner = User::find($partnerId);
                if (! $partner) return null;

                $page = $this->threadPage($authId, (int) $partnerId, null, self::PAGE_SIZE, $viewerId);

                return [
                    'id'           => $partner->id,
                    'fname'        => $partner->first_name,
                    'lname'        => $partner->last_name,
                    'image'        => $partner->image,
                    'sender'       => $partner->first_name . ' ' . $partner->last_name,
                    'email'        => $partner->email,
                    'unread_count' => Messages::where('from_id', $partnerId)
                        ->where('to_id', $authId)
                        ->where('is_new', 1)
                        ->count(),
                    'has_more'     => $page['has_more'],
                    'messages'     => $page['messages'],
                ];
            })->filter()->values();

            // Don't mark as read here - use POST /messages/mark-read endpoint instead owen commented out this section
            // Messages::where('to_id', $authId)->update(['is_new' => 0]);
            // their was a comment here something " Step 4: Return " I removed it
            return response()->json(['messages' => $results], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, SpamWordChecker $spam)
    {
        try{
            $validated = $request->validate([
                'msg' => 'required|string|max:2000',
                'to_id' => 'required|integer|exists:users,id',
            ]);
            $user = Auth::user();
            $userTo = User::findOrFail($request->to_id);

            $from_id = $user->id;
            $to_id = $validated['to_id'];

            // A program team member (Editor, Admin) writes as THEMSELVES, and a message to them reaches them: each person is their own dialog,
            // not one dialog under the owner's name. (Capital organizations below still write as their owner.)

            //Capital Editor / Admin: writes from the owner's shared inbox (index and thread read the same id)
            if((int) $user->user_type_id === 5) { //Capital From
                $from_id = $this->actingId($user);
                if ($this->sharesInboxWith($user, $from_id, (int) $to_id)) {
                    return response()->json(['message' => self::SHARED_INBOX_MESSAGE], 422);
                }
            }
            else if ((int) $userTo->user_type_id === 5) { //Capital To
                $role = $userTo->capital_profile?->role?->name;
                if (in_array($role, ['editor', 'admin', 'super_admin'], true)) {
                    $to_id = $userTo->capital_profile?->capital_owner_id ?? $userTo->id;
                }
            }

            // A team member writes as the organization, except to the organization's own owner: that is a reply between two people, so it
            // goes as themselves (otherwise the owner would be writing to the owner)
            if ((int) $to_id === (int) $from_id && (int) $user->id !== (int) $to_id) {
                $from_id = $user->id;
            }

            if($to_id == $from_id){
                return response()->json(['message' => 'You cannot send message to yourself'], 422);
            }

            // Email only for the first unread message from this sender, so a burst of messages is one email
            $hadUnreadFromSender = Messages::where('from_id', $from_id)->where('to_id', $to_id)->where('is_new', 1)->exists();

            $message = Messages::create([
                'msg' => $spam->censorText(preg_replace(
                    '/(\\+?\\d[\\d().\\s-]{7,}\\d|[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,})/',
                    '***',
                    $validated['msg']
                )),
                'to_id' => $to_id,
                'from_id' => $from_id,
            ] + (
                // from_id can be the organization owner, so keep who really wrote it
                // (skipped only until the sent_by_id migration has run, so sending never breaks)
                Schema::hasColumn('messages', 'sent_by_id') ? ['sent_by_id' => $user->id] : []
            ));

            // NotificationService
            $this->broadcastChat(['message' => 'Encrypted!'], $to_id);

            // Email the person who received the DM: the exact message, and a button straight to that chat.
            // Best effort: the message is already saved.
            if (! $hadUnreadFromSender) {
                try {
                    $receiver = User::with('settings')->find($to_id);
                    $sender = User::find($from_id);

                    if ($receiver?->email && $sender && ($receiver->settings?->email_notifications ?? true)) {
                        $root = match ((int) $receiver->user_type_id) {
                            1 => 'entrepreneur',
                            2 => 'investor',
                            3 => 'serviceProvider',
                            4 => 'programOrg',
                            5 => 'capitalOrg',
                            default => 'reviewerGrantOrg',
                        };
                        $senderName = trim($sender->first_name . ' ' . $sender->last_name);
                        // Sent by a team member on behalf of the owner: name the real writer too
                        if ((int) $user->id !== (int) $from_id) {
                            $senderName = trim($user->first_name . ' ' . $user->last_name) . ' · ' . $senderName;
                        }

                        (new EmailService())->send(
                            'New message from ' . $senderName,
                            'bids.conv_mail',
                            [
                                'sender'       => $senderName,
                                'msg'          => $validated['msg'],
                                // Opens the chat with this sender ("@" marks an id the app obfuscates)
                                'action_url'   => EmailLink::url("dashboard.{$root}.messages?chat=@{$from_id}", $receiver),
                                'action_label' => 'View Message',
                                // From an organisation member the email wears the organisation's look and name
                                'brand'        => EmailBrand::forUser($sender),
                            ],
                            $receiver->email
                        );
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Message email failed: ' . $e->getMessage());
                }
            }

            return response()->json([
                'message' => 'Message Sent!',
                'text' => $validated['msg']
            ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /**
     * Mark messages as read then comment the logic the one when get it makes it read so did this logic and created and endpont
     */
    public function markAsRead(Request $request)
    {
        try {
            $validated = $request->validate([
                'partner_id' => 'required|integer|exists:users,id',
            ]);

            $authId = $this->actingId(Auth::user());
            $partnerId = $validated['partner_id'];

            $read = Messages::where('from_id', $partnerId)
                ->where('to_id', $authId)
                ->where('is_new', 1)
                ->update(['is_new' => 0]);

            // The writer sees "Read" at once
            if ($read > 0) {
                try {
                    event(new \App\Events\ChatSignal('Read', (int) $partnerId, ['by_id' => $authId]));
                } catch (Throwable $e) {
                    Log::warning('Read broadcast failed: '.$e->getMessage());
                }
            }

            return response()->json(['message' => 'Messages marked as read'], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    # S E R V I C E   M E S S A G E   M E T H O D S
    public function serviceMessages()
    {
        try{
            $userId = Auth::id();

            $threads = ServiceMessages::where('to_id', $userId)
                ->groupBy('from_id')->latest()->get();

            $results = $threads->filter(fn($t) => User::find($t->from_id))->map(function ($thread) use ($userId) {
                $sender = User::find($thread->from_id);

                $messages = ServiceMessages::where(function ($q) use ($userId, $thread) {
                    $q->where('to_id', $userId)->orWhere('to_id', $thread->from_id);
                })->where(function ($q) use ($userId, $thread) {
                    $q->where('from_id', $userId)->orWhere('from_id', $thread->from_id);
                })->whereColumn('to_id', '!=', 'from_id')->latest()->get()
                    ->each(fn($m) => $m->sender = $m->from_id === $userId ? 'me' : '');

                $thread->sender   = $sender->first_name . ' ' . $sender->last_name;
                $thread->email    = $sender->email;
                $thread->messages = $messages;
                return $thread;
            })->values();

            ServiceMessages::where('to_id', $userId)->update(['new' => 0]);

            return response()->json(['messages' => $results]);
        }
        catch(Exception $e){
            ErrorLogService::report($e, ['user_id' => Auth::id()]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function serviceMessagesCount(int $toId)
    {
        $count = ServiceMessages::where('to_id', $toId)->where('new', 1)->count();
        return response()->json(['count' => $count]);
    }




    public function serviceMsg(Request $request)
    {
        try {
            $validated = $request->validate([
                'service_id' => 'required|integer|exists:services,id',
                'msg'        => 'required|string|max:2000',
            ]);

            $booker = Auth::user();
            $owner  = Services::findOrFail($validated['service_id']);

            ServiceMessages::create([
                'booker_id'        => $booker->id,
                'service_id'       => $validated['service_id'],
                'service_owner_id' => $owner->user_id,
                'msg'              => $validated['msg'],
                'to_id'            => $owner->user_id,
                'from_id'          => $booker->id,
            ]);

            $this->broadcastChat(['message' => 'new message'], $owner->user_id);

            return response()->json(['message' => 'Message sent.'], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function serviceReply(Request $request)
    {
        try {
            $validated = $request->validate([
                'msg'        => 'required|string|max:2000',
                'service_id' => 'nullable|integer',
                'msg_id'     => 'nullable|integer|exists:service_messages,id',
                'to_id'      => 'nullable|integer|exists:users,id',
            ]);

            $authUser = Auth::user();

            // Service-context reply
            if (!empty($validated['service_id'])) {
                $msg     = ServiceMessages::findOrFail($validated['msg_id']);
                $toId    = $msg->booker_id === $authUser->id ? $msg->service_owner_id : $msg->booker_id;
                $fromId  = $authUser->id;

                ServiceMessages::create([
                    'booker_id'        => $msg->booker_id,
                    'service_id'       => $validated['service_id'],
                    'service_owner_id' => $msg->service_owner_id,
                    'msg'              => $validated['msg'],
                    'to_id'            => $toId,
                    'from_id'          => $fromId,
                ]);

                $this->broadcastChat(['message' => 'new message'], $toId);
                return response()->json(['message' => 'Message sent.', 'status' => 200], 200);
            }

            // General conv. reply (Program / Capital / Investor)
            $fromId = $authUser->id;
            $toId   = $validated['to_id'];

            $resolveOwnerId = function (User $user, string $type) {
                if ($type === 'program') {
                    $user->loadMissing('organizationRole.role');
                    $role = $user->organizationRole?->role?->name;
                    return in_array($role, ['editor', 'admin']) ? $user->organizationOwnerId() : $user->id;
                }

                $profile = $user->capital_profile;
                $role    = $profile?->role?->name;
                return in_array($role, ['editor', 'admin', 'super_admin'], true) ? ($profile?->capital_owner_id ?? $user->id) : $user->id;
            };

            if ($authUser->user_type_id === 4) $fromId = $resolveOwnerId($authUser, 'program');
            if ((int) $authUser->user_type_id === 5) $fromId = $resolveOwnerId($authUser, 'capital');

            if ($request->filled('to_id')) {
                $userTo = User::findOrFail($toId);
                if ($userTo->user_type_id === 4) $toId = $resolveOwnerId($userTo, 'program');
                if ((int) $userTo->user_type_id === 5) $toId = $resolveOwnerId($userTo, 'capital');
            }

            ServiceMessages::create([
                'booker_id' => null, 'service_id' => null, 'service_owner_id' => null,
                'msg'       => $validated['msg'],
                'to_id'     => $toId,
                'from_id'   => $fromId,
            ]);

            $receiver = User::select('email')->findOrFail($validated['to_id']);
            $sender   = User::select('first_name')->findOrFail($fromId);

            $this->emailService->send(
                'Message Received', 'bids.conv_mail',
                ['sender' => $sender->first_name, 'msg' => $validated['msg']],
                $receiver->email
            );

            $this->broadcastChat(['message' => 'new message'], $toId);

            return response()->json(['message' => 'Message sent.', 'status' => 200], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

}
