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
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
            event(new ChatNotification($payload, $toId));
        } catch (Throwable $e) {
            Log::warning('Chat broadcast failed: ' . $e->getMessage());
        }
    }

    /**
     * A page of one conversation, newest first. `before` is the id of the oldest
     * message the client already has.
     */
    private function threadPage(int $authId, int $partnerId, ?int $before, int $limit): array
    {
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

        $messages = $rows->take($limit)->map(function ($msg) use ($authId, $authors) {
            $msg->sender = $msg->from_id === $authId ? 'me' : '';

            $author = $authors->get($msg->sent_by_id);
            $msg->author = $author ? [
                'id'    => $author->id,
                'name'  => $author->display_name ?: trim($author->first_name . ' ' . $author->last_name),
                'image' => $author->image,
                'is_me' => (int) $author->id === $authId,
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

            return response()->json(
                $this->threadPage(
                    (int) Auth::id(),
                    (int) $partnerId,
                    isset($validated['before']) ? (int) $validated['before'] : null,
                    (int) ($validated['limit'] ?? self::PAGE_SIZE)
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

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try{
            $authId = Auth::id();

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
            $results = $partnerIds->map(function ($partnerId) use ($authId) {
                $partner = User::find($partnerId);
                if (! $partner) return null;

                $page = $this->threadPage($authId, $partnerId, null, self::PAGE_SIZE);

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
    public function store(Request $request)
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

            // Program Editor
            if($user->user_type_id == 4) { // Organization program member
                $user->loadMissing('organizationRole.role');
                $role = $user->organizationRole?->role?->name;
                if($role == 'editor' || $role == 'admin'){
                    $from_id = $user->organizationOwnerId();
                }
            }
            else if ($userTo->user_type_id == 4) { // Organization program member
                $userTo->loadMissing('organizationRole.role');
                $role = $userTo->organizationRole?->role?->name;
                if ($role == 'editor' || $role == 'admin') {
                    $to_id = $userTo->organizationOwnerId();
                }
            }

            //Capital Editor
            if($user->user_type_id == 3) { //Capital From
                $role = $user->capital_profile?->role?->name;
                if( $role && ($role == 'editor' || $role == 'admin') ){
                    $from_id = $user->capital_profile?->capital_owner_id ?? $user->id;
                }
            }
            else if ($userTo->user_type_id == 3) { //Capital To
                $role = $userTo->capital_profile?->role?->name;
                if ($role && ($role == 'editor' || $role == 'admin')) {
                    $to_id = $userTo->capital_profile?->capital_owner_id ?? $userTo->id;
                }
            }

            if($to_id == $from_id){
                return response()->json(['message' => 'You cannot send message to yourself'], 422);
            }

            // Email only for the first unread message from this sender, so a burst of messages is one email
            $hadUnreadFromSender = Messages::where('from_id', $from_id)->where('to_id', $to_id)->where('is_new', 1)->exists();

            $message = Messages::create([
                'msg' => $validated['msg'],
                'to_id' => $to_id,
                'from_id' => $from_id,
                // from_id can be the organization owner, so keep who really wrote it
                'sent_by_id' => $user->id,
            ]);

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

                        (new EmailService())->send(
                            'New message from ' . $senderName,
                            'bids.conv_mail',
                            [
                                'sender'       => $senderName,
                                'msg'          => $validated['msg'],
                                // Opens the chat with this sender ("@" marks an id the app obfuscates)
                                'action_url'   => EmailLink::url("dashboard.{$root}.messages?chat=@{$from_id}"),
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

            $authId = Auth::id();
            $partnerId = $validated['partner_id'];

            Messages::where('from_id', $partnerId)
                ->where('to_id', $authId)
                ->where('is_new', 1)
                ->update(['is_new' => 0]);

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
                return ($role === 'editor' || $role === 'admin') ? ($profile?->capital_owner_id ?? $user->id) : $user->id;
            };

            if ($authUser->user_type_id === 4) $fromId = $resolveOwnerId($authUser, 'program');
            if ($authUser->user_type_id === 3) $fromId = $resolveOwnerId($authUser, 'capital');

            if ($request->filled('to_id')) {
                $userTo = User::findOrFail($toId);
                if ($userTo->user_type_id === 4) $toId = $resolveOwnerId($userTo, 'program');
                if ($userTo->user_type_id === 3) $toId = $resolveOwnerId($userTo, 'capital');
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
