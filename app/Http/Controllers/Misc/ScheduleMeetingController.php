<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Account\Account\Account\Account\testController;
use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Capital\StartupPitches;
use App\Models\Communication\Meeting;
use App\Models\Communication\Schedule;
use App\Models\Programs\ProgramApplication;
use App\Models\Services\ServiceBooking;
use App\Service\Misc\ErrorLogService;
use App\Support\ApiContract;
use App\Support\CollectionList;
use App\Support\UserDateFormatter;
use Carbon\Carbon;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Response;
use Session;

class ScheduleMeetingController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Schedule a meeting with one or many people.
     * POST /create-meeting  { client_ids: [..] (or client_id), date, time, title, description, link }
     * Each invited person gets their own row, so each one answers (pending, accepted, declined) on their own.
     */
    public function create_meeting(Request $request)
    {
        try{
            $host = Auth::user();

            $request->validate([
                'client_id'    => 'required_without:client_ids|integer|exists:users,id',
                'client_ids'   => 'required_without:client_id|array|min:1|max:50',
                'client_ids.*' => 'integer|distinct|exists:users,id',
                'date'        => 'required|date', // format: Y-m-d
                'time'        => 'required|date_format:H:i', // 24-hour format like 14:30
                // The zone the date and time were chosen in (the client sends the person's Settings > Timezone)
                'timezone'    => 'nullable|string|timezone:all',
                'title'       => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'link'        => 'required|url|max:200',
            ]);

            $ids = collect($request->input('client_ids', [$request->client_id]))
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id !== (int) $host->id)
                ->unique()->values();

            if ($ids->isEmpty()) {
                return response()->json(['message' => 'Choose at least one person other than yourself to invite.'], 422);
            }

            // The date and time are the host's clock in their zone; starts_at is that exact moment (UTC) for everyone else
            $zone = UserDateFormatter::zone($request->input('timezone') ?: $this->zoneOf($host));
            $startsAt = Carbon::parse($request->date . ' ' . $request->time, $zone)->utc();

            // Every row of one meeting is created together, so they share the same created_at (that is how they are grouped)
            $createdAt = now();
            $hostName = trim($host->first_name.' '.$host->last_name);
            $meetings = collect();

            foreach (User::whereIn('id', $ids)->get() as $client) {
                $meeting = new Meeting([
                    'host_id'      => $host->id,
                    'client_id'    => $client->id,
                    'host_name'    => $hostName,
                    'client_name'  => trim($client->first_name.' '.$client->last_name),
                    'date'         => $request->date,
                    'time'         => $request->time,
                    'timezone'     => $zone,
                    'starts_at'    => $startsAt,
                    'title'        => $request->title,
                    'description'  => $request->description,
                    'link'         => $request->link,
                    'status'       => 'pending',
                ]);
                $meeting->created_at = $createdAt;
                $meeting->updated_at = $createdAt;
                $meeting->save();
                $meetings->push($meeting);

                // Tell each person in the app and by email (best effort)
                $when = $this->meetingWhen($meeting, $client);
                try {
                    $this->notification->create($client->id, $host->id,
                        "New meeting invitation\n{$hostName} invited you to \"{$meeting->title}\" on {$when}. Accept or decline it in Meetings.",
                        'meeting', 'meeting');
                } catch (\Throwable $e) {
                    ErrorLogService::report($e, ['meeting_id' => $meeting->id]);
                }
                $this->emailMeetingUpdate($meeting, $client, $host, 'invited');
            }

            // And a note for the host that the invitations went out
            try {
                $count = $meetings->count();
                $this->notification->create($host->id, $host->id,
                    "Meeting scheduled\n\"{$request->title}\" was sent to {$count} " . ($count === 1 ? 'person' : 'people') . '. You will be told when they respond.',
                    'meeting', 'meeting');
            } catch (\Throwable $e) {
                ErrorLogService::report($e, ['host_id' => $host->id]);
            }

            return response()->json([
                'message' => $meetings->count() === 1
                    ? 'Meeting scheduled. ' . $meetings->first()->client_name . ' has been invited by email and in the app.'
                    : 'Meeting scheduled. ' . $meetings->count() . ' people have been invited by email and in the app.',
                'data' => $meetings->first(),
            ], 201);
        }
        catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->validator->errors()->first(), 'errors' => $e->errors()], 422);
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

    /** Old rows were saved as "active"; that means the invitation has not been answered yet */
    private function responseOf(Meeting $meeting): string
    {
        return in_array($meeting->status, ['accepted', 'declined', 'cancelled'], true) ? $meeting->status : 'pending';
    }

    /** All the rows of one meeting (one per invited person) */
    private function groupOf(Meeting $meeting)
    {
        return Meeting::where('host_id', $meeting->host_id)
            ->where('title', $meeting->title)
            ->where('link', $meeting->link)
            ->where('date', $meeting->getRawOriginal('date'))
            ->where('time', $meeting->getRawOriginal('time'))
            ->where('created_at', $meeting->getRawOriginal('created_at'))
            ->get();
    }

    public function meetings(Request $request)
    {
        try{
            $user = Auth::user();
            $user_id = Auth::id();
            $type = $user->user_type_id;

            if($type == 2 || $type == 3 || $type == 5){ // 5: a capital team member works on the capital owner's calendar
                $user = $type == 2 ? $this->get_role() : $this->get_role2();
                if (in_array($user->role, ['editor', 'viewer', 'admin'])) {
                    $user_id = $type == 2 ? $user->program_owner_id : $user->capital_owner_id;
                }
            }

            $meetings = $this->meetingRows((int) $user_id);

            // ?page / per_page / search / filters / sort / export: the meetings table (the calendar still reads the whole list)
            if (ApiContract::wantsPagination($request) || $request->boolean('export')
                || $request->filled('search') || $request->filled('filters') || $request->filled('sort')) {
                return $this->meetingList($meetings, $request);
            }

            return response()->json(['meetings' => $meetings->values()], 200, [], JSON_UNESCAPED_SLASHES);
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

    /** The key that ties together the rows of one meeting (one row per invited person) */
    private function groupKey(Meeting $meeting): string
    {
        return implode('|', [$meeting->host_id, $meeting->title, $meeting->link, $meeting->getRawOriginal('date'), $meeting->getRawOriginal('time'), $meeting->getRawOriginal('created_at')]);
    }

    /**
     * Every meeting the person hosts or is invited to, as the page shows it.
     * The host sees one meeting with everyone invited; an invitee sees their own invitation and who else is in it.
     * The rows of every meeting are read in one query (not one per meeting).
     */
    private function meetingRows(int $user_id)
    {
        $rows = Meeting::where('host_id', $user_id)->orWhere('client_id', $user_id)->latest()->get();

        $groups = $rows->isEmpty() ? collect() : Meeting::whereIn('host_id', $rows->pluck('host_id')->unique()->values())
            ->whereIn('created_at', $rows->map(fn (Meeting $m) => $m->getRawOriginal('created_at'))->unique()->values())
            ->get()
            ->groupBy(fn (Meeting $m) => $this->groupKey($m));

        $seen = [];
        $meetings = collect();

        foreach ($rows as $meeting) {
            $isHost = (int) $meeting->host_id === (int) $user_id;
            $key = $this->groupKey($meeting);
            $group = $groups->get($key, collect([$meeting]));

            if ($isHost) {
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
            }

            $attendees = $group->map(fn (Meeting $row) => [
                'user_id' => $row->client_id,
                'name' => $row->client_name,
                'response' => $this->responseOf($row),
                'is_me' => (int) $row->client_id === (int) $user_id,
            ])->values();

            if (! $isHost) {
                // The host first, then everyone else invited
                $attendees->prepend(['user_id' => $meeting->host_id, 'name' => $meeting->host_name, 'response' => 'host', 'is_me' => false]);
            }

            $entry = $meeting->toArray();
            $entry['is_host'] = $isHost;
            $entry['attendees'] = $attendees->all();
            $entry['my_response'] = $isHost ? null : $this->responseOf($meeting);

            if ($isHost) {
                $responses = $group->map(fn (Meeting $row) => $this->responseOf($row));
                $entry['client_name'] = $group->pluck('client_name')->filter()->implode(', ');
                $entry['status'] = $responses->every(fn ($r) => $r === 'cancelled') ? 'cancelled' : 'active';
                $entry['summary'] = collect(['accepted', 'pending', 'declined'])
                    ->map(fn ($r) => $responses->filter(fn ($x) => $x === $r)->count() ? $responses->filter(fn ($x) => $x === $r)->count() . ' ' . $r : null)
                    ->filter()->implode(', ');
            } else {
                $entry['status'] = $this->responseOf($meeting);
            }

            // For the table: when it starts (sortable), who it is with, and upcoming / past / cancelled
            $at = $this->startsAt($meeting);
            $entry['starts_at'] = $at?->toIso8601String();
            $entry['with_name'] = $isHost ? $this->withNames($group) : $meeting->host_name;
            // when it was scheduled (UTC, the stored moment), for the "Scheduled on" filter
            $entry['scheduled_at'] = $meeting->created_at ? Carbon::parse($meeting->getRawOriginal('created_at'))->toDateString() : null;
            $entry['bucket'] = $entry['status'] === 'cancelled' || in_array($entry['my_response'], ['cancelled', 'declined'], true)
                ? 'cancelled'
                : ($at && $at->isFuture() ? 'upcoming' : 'past');

            $meetings->push($entry);
        }

        return $meetings;
    }

    /** The exact moment a meeting starts (UTC): starts_at, or its date and time read in its own zone (older rows) */
    private function startsAt(Meeting $meeting): ?Carbon
    {
        try {
            $stored = $meeting->getRawOriginal('starts_at');
            if ($stored) {
                return Carbon::parse($stored, 'UTC');
            }
            $zone = UserDateFormatter::zone($meeting->getRawOriginal('timezone'));

            return Carbon::parse(substr((string) $meeting->getRawOriginal('date'), 0, 10) . ' ' . substr((string) $meeting->getRawOriginal('time'), 0, 5), $zone)->utc();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** A person's Settings > Timezone (Africa/Nairobi when never set) */
    private function zoneOf(?User $user): string
    {
        $zone = $user ? \App\Models\Auth\UserSetting::query()->where('user_id', $user->id)->value('timezone') : null;

        return UserDateFormatter::zone($zone);
    }

    /** "Amina Otieno, Brian Kim" or "Amina Otieno +2 more" for the people a host invited */
    private function withNames($group): string
    {
        $names = $group->pluck('client_name')->filter()->values();

        return $names->count() > 2 ? $names[0] . ' +' . ($names->count() - 1) . ' more' : $names->implode(', ');
    }

    /** The meetings table: search, the upcoming / past / cancelled filter, sort, page and export (CollectionList). */
    private function meetingList($meetings, Request $request)
    {
        // ?bucket=upcoming|past|cancelled: the buttons above the table narrow the rows (and the filter counts); the panel's
        // filters stay free for the person (filters[bucket][] still works for older screens)
        $rows = in_array($request->query('bucket'), ['upcoming', 'past', 'cancelled'], true)
            ? $meetings->where('bucket', $request->query('bucket'))->values()
            : $meetings;
        $list = CollectionList::for($rows, $request)
            ->search(fn ($m) => implode(' ', [$m['title'] ?? '', $m['description'] ?? '', $m['with_name'] ?? '', $m['date'] ?? '', $m['status'] ?? '']))
            ->filter('id', fn ($m) => $m['id'])
            ->filter('bucket', fn ($m) => $m['bucket'])
            // Described filters: the meetings panel, chips and URL state come from `filter_schema`
            ->options('role', fn ($m) => ! empty($m['is_host']) ? 'host' : 'invited', label: 'app:listFilterMyRole', fallback: 'My role',
                values: ['host', 'invited'], optionLabels: ['host' => 'app:listFilterHostedByMe', 'invited' => 'app:invited'])
            ->options('response', fn ($m) => empty($m['is_host']) ? ($m['my_response'] ?? null) : null, label: 'app:listFilterMyAnswer', fallback: 'My answer',
                values: ['pending', 'accepted', 'declined', 'cancelled'],
                optionLabels: ['pending' => 'app:pending2', 'accepted' => 'app:accepted', 'declined' => 'app:declined', 'cancelled' => 'app:cancelled'])
            ->dateRange('date', fn ($m) => (string) ($m['starts_at'] ?? ''), label: 'app:listFilterMeetingDate', fallback: 'Meeting date',
                presets: ['today', 'next7Days', 'next30Days', 'yesterday', 'last7Days', 'last30Days', 'thisMonth', 'lastMonth', 'thisYear'])
            ->dateRange('created', fn ($m) => (string) ($m['scheduled_at'] ?? ''), label: 'app:listFilterScheduledOn', fallback: 'Scheduled on')
            ->boolean('video', fn ($m) => trim((string) ($m['link'] ?? '')) !== '', label: 'shared:meetings.table.videoCall', fallback: 'Video call')
            ->sortable([
                'at' => fn ($m) => (string) ($m['starts_at'] ?? ''),
                'title' => fn ($m) => (string) ($m['title'] ?? ''),
                'with' => fn ($m) => (string) ($m['with_name'] ?? ''),
            ], default: '-at');
        [$items, $page] = $list->fetch();

        $counts = $meetings->countBy('bucket');
        $data = [
            'items' => $items->values(),
            'facets' => $list->facets(['bucket']),
            'filter_schema' => $list->filterSchema(),
            // the filter buttons count every meeting (they do not change with the search)
            'stats' => [
                'all' => $meetings->count(),
                'upcoming' => (int) ($counts['upcoming'] ?? 0),
                'past' => (int) ($counts['past'] ?? 0),
                'cancelled' => (int) ($counts['cancelled'] ?? 0),
            ],
        ];
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }
        if ($list->wantsExport()) {
            $data['export_truncated'] = $list->exportTruncated();
        }

        return response()->json(['success' => true, 'message' => 'Meetings retrieved', 'data' => $data], 200, [], JSON_UNESCAPED_SLASHES);
    }

    /**
     * "Monday, 12 October 2026 at 12:30 EAT": when a meeting starts, in the reader's own time zone (Settings > Timezone),
     * so a 07:00 Nairobi meeting reads "at 23:00 EST" (the day before) for someone in New York.
     */
    private function meetingWhen(Meeting $meeting, ?User $reader = null): string
    {
        try {
            $at = $this->startsAt($meeting);
            if (! $at) {
                return trim($meeting->date . ' ' . $meeting->time);
            }

            return $at->copy()->setTimezone($this->zoneOf($reader))->format('l, j F Y \a\t H:i T');
        } catch (\Throwable $e) {
            return trim($meeting->date . ' ' . $meeting->time);
        }
    }

    /**
     * Emails a person about a meeting. Best effort, it never fails the action.
     * @param string $kind invited | cancelled | accepted | declined
     */
    private function emailMeetingUpdate(Meeting $meeting, ?User $recipient, User $actor, string $kind): void
    {
        try {
            if (! $recipient || ! $recipient->email) {
                return;
            }

            $actorName = trim($actor->first_name . ' ' . $actor->last_name) ?: 'Someone';
            [$title, $body] = match ($kind) {
                'cancelled' => ["{$actorName} cancelled your meeting", "{$actorName} has cancelled the meeting below."],
                'accepted' => ["{$actorName} accepted your meeting", "{$actorName} accepted your invitation to the meeting below."],
                'declined' => ["{$actorName} declined your meeting", "{$actorName} cannot join the meeting below. You can message them to agree a new time."],
                default => ["{$actorName} scheduled a meeting with you", "{$actorName} invited you to the meeting below. Please accept or decline it in Meetings."],
            };

            (new \App\Service\Notification\EmailService())->send($title, 'meeting_update', [
                'title' => $title,
                'body' => $body,
                'recipientName' => $recipient->first_name ?: ($recipient->display_name ?: 'there'),
                'meeting_title' => $meeting->title,
                'when' => $this->meetingWhen($meeting, $recipient),
                'otherName' => $actorName,
                'description' => $meeting->description,
                'kind' => $kind,
                'cancelled' => $kind === 'cancelled',
                'joinUrl' => $meeting->link,
            ], $recipient->email);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['meeting_id' => $meeting->id]);
        }
    }

    /**
     * People you can schedule a meeting with.
     * GET /meeting-people?search=&page=&per_page=
     * No search: the people you have already met with come first (suggestions). With a search: anyone on the
     * platform whose name, username or email matches, so you can find someone you have not worked with yet.
     */
    public function people(Request $request)
    {
        try {
            $me = Auth::user();
            $search = trim((string) $request->query('search', ''));
            $perPage = min(max((int) $request->query('per_page', 10), 1), 50);
            $page = max((int) $request->query('page', 1), 1);

            $query = User::where('id', '!=', $me->id)->whereBetween('user_type_id', [1, 6]);

            if ($search !== '') {
                $like = '%' . $search . '%';
                $startsWith = $search . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('display_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
                })
                // People whose name starts with what was typed come first, like a typeahead
                ->orderByRaw("CASE WHEN first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ? THEN 0 ELSE 1 END", [$startsWith, $startsWith, $startsWith]);
            } else {
                $met = Meeting::where('host_id', $me->id)->pluck('client_id')
                    ->merge(Meeting::where('client_id', $me->id)->pluck('host_id'))
                    ->unique()->values();

                if ($met->isNotEmpty()) {
                    $query->orderByRaw('CASE WHEN id IN (' . $met->map(fn ($id) => (int) $id)->implode(',') . ') THEN 0 ELSE 1 END');
                }
            }

            $users = $query->orderBy('first_name')->orderBy('id')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage + 1)
                ->get(['id', 'first_name', 'last_name', 'display_name', 'email', 'image', 'city', 'country', 'user_type_id']);

            $hasMore = $users->count() > $perPage;

            $roles = [
                1 => 'Entrepreneur', 2 => 'Investor', 3 => 'Service Provider',
                4 => 'Program Organization', 5 => 'Capital Organization', 6 => 'Reviewer',
            ];

            return response()->json([
                'data' => $users->take($perPage)->values()->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => trim($user->first_name . ' ' . $user->last_name) ?: ($user->display_name ?: $user->email),
                    'email' => $user->email,
                    'image' => $user->image,
                    'role' => $roles[$user->user_type_id] ?? 'Member',
                    'location' => collect([$user->city, $user->country])->filter()->implode(', '),
                ]),
                'meta' => ['page' => $page, 'per_page' => $perPage, 'has_more' => $hasMore],
            ], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['search' => $request->query('search')]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Cancel a meeting. The host cancels it for everyone; an invited person cancelling is the same as declining.
     * POST /cancel-meeting  { id }
     */
    public function cancel_meeting(Request $request)
    {
        try{
            $meeting = Meeting::findOrFail($request->id);
            $me = Auth::user();

            if ((int) $meeting->host_id !== (int) $me->id && (int) $meeting->client_id !== (int) $me->id) {
                return response()->json(['message' => 'Only the people in this meeting can cancel it.'], 403);
            }

            if ((int) $meeting->client_id === (int) $me->id && (int) $meeting->host_id !== (int) $me->id) {
                return $this->answer($meeting, $me, 'declined');
            }

            $rows = $this->groupOf($meeting)->filter(fn (Meeting $row) => $row->status !== 'cancelled');
            if ($rows->isEmpty()) {
                return response()->json(['message' => 'This meeting is already cancelled.'], 422);
            }

            foreach ($rows as $row) {
                $declined = $row->status === 'declined';
                $row->status = 'cancelled';
                $row->save();

                // Someone who already declined does not need to hear about it again
                if ($declined) {
                    continue;
                }
                $other = User::find($row->client_id);
                if ($other) {
                    $myName = trim($me->first_name . ' ' . $me->last_name);
                    try {
                        $this->notification->create($other->id, $me->id,
                            "Meeting cancelled\n{$myName} cancelled \"{$row->title}\" on " . $this->meetingWhen($row, $other) . '.',
                            'meeting', 'meeting');
                    } catch (\Throwable $e) {
                        ErrorLogService::report($e, ['meeting_id' => $row->id]);
                    }
                    $this->emailMeetingUpdate($row, $other, $me, 'cancelled');
                }
            }

            $count = $rows->count();

            return response()->json(['message' => 'Meeting cancelled. ' . ($count === 1 ? $rows->first()->client_name . ' has' : $count . ' people have') . ' been notified by email and in the app.'], 200);
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
     * An invited person accepts or declines.
     * POST /respond-meeting  { id, response: accepted|declined }
     */
    public function respond_meeting(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required|integer',
                'response' => 'required|in:accepted,declined',
            ]);

            $meeting = Meeting::findOrFail($request->id);
            $me = Auth::user();

            if ((int) $meeting->client_id !== (int) $me->id) {
                return response()->json(['message' => 'Only the person invited can answer this meeting.'], 403);
            }

            return $this->answer($meeting, $me, $request->response);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->validator->errors()->first(), 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);

            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /** Saves an invited person's answer and tells the host (in the app and by email) */
    private function answer(Meeting $meeting, User $me, string $response)
    {
        if ($meeting->status === 'cancelled') {
            return response()->json(['message' => 'This meeting was cancelled by the host.'], 422);
        }

        if ($meeting->status === $response) {
            return response()->json(['message' => 'You already ' . ($response === 'accepted' ? 'accepted' : 'declined') . ' this meeting.'], 422);
        }

        $meeting->status = $response;
        $meeting->save();

        $host = User::find($meeting->host_id);
        if ($host) {
            $myName = trim($me->first_name . ' ' . $me->last_name);
            $verb = $response === 'accepted' ? 'accepted' : 'declined';
            try {
                $this->notification->create($host->id, $me->id,
                    "Meeting {$verb}\n{$myName} {$verb} \"{$meeting->title}\" on " . $this->meetingWhen($meeting, $host) . '.',
                    'meeting', 'meeting');
            } catch (\Throwable $e) {
                ErrorLogService::report($e, ['meeting_id' => $meeting->id]);
            }
            $this->emailMeetingUpdate($meeting, $host, $me, $response);
        }

        return response()->json([
            'message' => $response === 'accepted'
                ? 'You accepted the meeting. ' . ($host?->first_name ?? 'The host') . ' has been told.'
                : 'You declined the meeting. ' . ($host?->first_name ?? 'The host') . ' has been told.',
        ], 200);
    }

    public function clients_list()
    {
        try{
            $user = Auth::user(); //71;
            $user_id = Auth::id();
            $type = $user->user_type_id;
            $client_ids = [];

            if($type == 1) //Investor
            {
                $ids = ServiceBooking::where('service_owner_id', $user_id)->pluck('booker_id')
                    ->unique();

                $ids2 = AcceptedBids::where('owner_id', $user_id)->pluck('investor_id')
                    ->unique();

                $client_ids = $ids->merge($ids2)->unique()->values()->toArray();
            }

            else if($type == 2) //Program
            {
                $user = $this->get_role();
                if (in_array($user->role, ['editor', 'viewer', 'admin', 'super_admin'])) {
                    $user_id = $user->program_owner_id;
                }
                $client_ids = ProgramApplication::where('program_owner_id', $user_id)->pluck('user_id')
                    ->unique()->toArray();
            }
            else if($type == 5) //Capital provider
            {
                $user = $this->get_role2();
                if (in_array($user->role, ['editor', 'viewer', 'admin', 'super_admin'])) {
                    $user_id = $user->capital_owner_id;
                }
                $client_ids = StartupPitches::where('capital_owner_id', $user_id)->notRejected()->pluck('user_id')
                    ->unique()->toArray();
            }
            else if($type == 4) //Business
            {
                $ids = ProgramApplication::where('user_id', $user_id)->pluck('program_owner_id')
                    ->unique();
                $ids2 = StartupPitches::where('user_id', $user_id)->notRejected()->pluck('capital_owner_id')
                    ->unique();
                $ids3 = ServiceBooking::where('booker_id', $user_id)->pluck('service_owner_id')
                    ->unique();
                $ids4 = AcceptedBids::where('owner_id', $user_id)->pluck('investor_id')
                    ->unique();
                $client_ids = $ids->merge($ids2)->merge($ids3)->merge($ids4)->unique()
                    ->values()->toArray();
            }
            else if($type == 3) //Service provider: the people who booked their services
            {
                $ids = ServiceBooking::where('service_owner_id', $user_id)->pluck('booker_id')
                    ->unique();
                $client_ids = $ids->unique()->values()->toArray();
            }

            $clients = User::whereIn('id',$client_ids)->get();
            return response()->json(['clients' => $clients], 200);
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

    // S C H E D U L E S

    public function create_schedule(Request $request)
    {
        try{
            $hasSchedule = Schedule::where('user_id',Auth::id())->first();
            $request->validate([
                'timezone'    => 'required|string|max:100',
                'day'         => 'required|array|max:200',
                'start_hour'  => 'required|array|max:200',
                'end_hour'    => 'required|array|max:200',
            ]);
            if($hasSchedule)
            {
                $hasSchedule->update([
                    'timezone'    => $request->timezone,
                    'day'         => json_encode($request->day),
                    'start_hour'  => json_encode($request->start_hour),
                    'end_hour'    => json_encode($request->end_hour),
                ]);
                return response()->json(['message' => 'Schedule updated successfully.'], 200);
            }
            $schedule = Schedule::create([
                'user_id'     => Auth::id(),
                'timezone'    => $request->timezone,
                'day'         => json_encode($request->day),
                'start_hour'  => json_encode($request->start_hour),
                'end_hour'    => json_encode($request->end_hour),
            ]);
            return response()->json(['message' => 'Schedule created successfully.'], 200);
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

    public function schedules()
    {
        try{
            $user = Auth::user();
            $user_id = Auth::id();
            $type = $user->user_type_id;

            if($type == 2 || $type == 3 || $type == 5){ // 5: a capital team member works on the capital owner's calendar
                $user = $type == 2 ? $this->get_role() : $this->get_role2();
                if (in_array($user->role, ['editor', 'viewer', 'admin'])) {
                    $user_id = $type == 2 ? $user->program_owner_id : $user->capital_owner_id;
                }
            }
            $schedules = Schedule::where('user_id',$user_id)->latest()->get();

//            $flattened = [];
//            foreach ($schedules as $schedule) {
//                $days = json_decode($schedule->day, true);
//                $starts = json_decode($schedule->start_hour, true);
//                $ends = json_decode($schedule->end_hour, true);
//
//                if (!is_array($days) || !is_array($starts) || !is_array($ends)) {
//                    continue; // skip invalid data
//                }
//                foreach ($days as $index => $day) {
//                    $flattened[] = [
//                        'day' => $day,
//                        'start' => $starts[$index] ?? null,
//                        'end' => $ends[$index] ?? null,
//                    ];
//                }
//            }

            return response()->json(['schedules' => $schedules], 200, [], JSON_UNESCAPED_SLASHES);
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

    public function delete_schedule($id)
    {
        try{
            // Only your own availability
            $deleted = Schedule::where('id', $id)->where('user_id', Auth::id())->delete();
            if (! $deleted) {
                return response()->json(['message' => 'Schedule not found.'], 404);
            }
            return response()->json(['message' => 'Deleted!'], 200);
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


    public function client_schedules($client_id)
    {
        try{
            $user = Auth::user();
            $type = $user->user_type_id;
            $schedules = Schedule::where('user_id',$client_id)->latest()->first();
            if($schedules)
                return response()->json(['schedules' => $schedules], 200, [], JSON_UNESCAPED_SLASHES);
            else
                return response()->json(['schedules' => 'N/A"'], 200, [], JSON_UNESCAPED_SLASHES);
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

   #____________________________H e l p e r s________________________
    public function get_role()
    {
        $user = Auth::user()->load('organizationRole.role');
        $user->role = $user->organizationRole?->role?->name ?? 'super-admin';
        $user->program_owner_id = $user->organizationOwnerId();
        return $user;
    }

    public function get_role2()
    {
        $user = Auth::user()->load('capital_profile.role');
        $user->role = $user->capital_profile?->role?->name ?? 'super-admin';
        $user->capital_owner_id = $user->capital_profile?->capital_owner_id;
        return $user;
    }

}
