<?php

namespace App\Http\Controllers\Misc;

use App\Events\ChatNotification;
use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Communication\Messages;
use App\Models\Services\ServiceMessages;
use App\Models\Services\Services;
use App\Service\Misc\ErrorLogService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
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

            // Step 2: Build a structured response for each chat partner
            $results = $partnerIds->map(function ($partnerId) use ($authId) {
                // Fetch full conversation (both directions)
                $conversation = Messages::where(function ($q) use ($authId, $partnerId) {
                    $q->where('from_id', $authId)
                        ->where('to_id', $partnerId);
                })
                    ->orWhere(function ($q) use ($authId, $partnerId) {
                        $q->where('from_id', $partnerId)
                            ->where('to_id', $authId);
                    })
                    ->latest()
                    ->get()
                    ->map(function ($msg) use ($authId) {
                        // Tag sender for frontend use
                        $msg->sender = $msg->from_id === $authId ? 'me' : '';
                        return $msg;
                    });

                // Get the partner (sender) info
                $partner = User::find($partnerId);
                if (!$partner) return null;

                return [
                    'sender'   => $partner->fname . ' ' . $partner->lname,
                    'email'    => $partner->email,
                    'messages' => $conversation,
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

            // Grant Editor
            if($user->user_type_id == 2) { //Grant From
                $role = $user->grant_profile?->role?->name;
                if($role == 'editor' || $role == 'admin'){
                    $from_id = $user->grant_profile?->grant_owner_id ?? $user->id;
                }
            }
            else if ($userTo->user_type_id == 2) { //Grant To
                $role = $userTo->grant_profile?->role?->name;
                if ($role == 'editor' || $role == 'admin') {
                    $to_id = $userTo->grant_profile?->grant_owner_id ?? $userTo->id;
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

            $message = Messages::create([
                'msg' => $validated['msg'],
                'to_id' => $to_id,
                'from_id' => $from_id,
            ]);

            // NotificationService
            event(new ChatNotification(['message' => 'Encrypted!'], $to_id));

            if( ($userTo->user_type_id == 4 && $user->user_type_id == 1) ||
                ($user->user_type_id == 4 && $userTo->user_type_id == 1) ||
                ($user->user_type_id == 4 && $userTo->user_type_id == 5) ||
                ($user->user_type_id == 5 && $userTo->user_type_id == 4)
            ){
                //E m a i l
                $receiver = User::find($to_id);
                $sender = User::find($from_id);

                $user['to'] = $receiver->email;
                $info=[ 'sender'=>$sender->fname, 'msg'=>$validated['msg'] ];
                Mail::send('bids.conv_mail', $info, function($msg) use ($user){
                    $msg->to($user['to']); $msg->subject('Message Received.');
                });
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

                $thread->sender   = $sender->fname . ' ' . $sender->lname;
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

            event(new ChatNotification(['message' => 'new message'], $owner->user_id));

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

                event(new ChatNotification(['message' => 'new message'], $toId));
                return response()->json(['message' => 'Message sent.', 'status' => 200], 200);
            }

            // General conv. reply (Grant / Capital / Investor)
            $fromId = $authUser->id;
            $toId   = $validated['to_id'];

            $resolveOwnerId = function (User $user, string $type) {
                $profile = $type === 'grant'   ? $user->grant_profile   : $user->capital_profile;
                $role    = $profile?->role?->name;
                $ownerKey = $type === 'grant'  ? 'grant_owner_id'       : 'capital_owner_id';
                return ($role === 'editor' || $role === 'admin') ? ($profile?->$ownerKey ?? $user->id) : $user->id;
            };

            if ($authUser->user_type_id === 2) $fromId = $resolveOwnerId($authUser, 'grant');
            if ($authUser->user_type_id === 3) $fromId = $resolveOwnerId($authUser, 'capital');

            if ($request->filled('to_id')) {
                $userTo = User::findOrFail($toId);
                if ($userTo->user_type_id === 2) $toId = $resolveOwnerId($userTo, 'grant');
                if ($userTo->user_type_id === 3) $toId = $resolveOwnerId($userTo, 'capital');
            }

            ServiceMessages::create([
                'booker_id' => null, 'service_id' => null, 'service_owner_id' => null,
                'msg'       => $validated['msg'],
                'to_id'     => $toId,
                'from_id'   => $fromId,
            ]);

            $receiver = User::select('email')->findOrFail($validated['to_id']);
            $sender   = User::select('fname')->findOrFail($fromId);

            $this->emailService->send(
                'Message Received', 'bids.conv_mail',
                ['sender' => $sender->fname, 'msg' => $validated['msg']],
                $receiver->email
            );

            event(new ChatNotification(['message' => 'new message'], $toId));

            return response()->json(['message' => 'Message sent.', 'status' => 200], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

}
