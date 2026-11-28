<?php

namespace App\Http\Controllers\Service;

use App\Service\Services\ServiceLists;
use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Finance\Transactions;
use App\Models\Misc\Setting;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\ServiceReviews;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use App\Service\Services\ServiceBookingFlow;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;

class ServiceMilestoneController extends Controller
{
    protected $Client;
    protected BalanceService $balance;
    protected LiprW2W $liprW2W;
    protected $convert;

    public function __construct(StripeClient $client)
    {
        parent::__construct();

        $this->Client = $client;
        $this->balance = new BalanceService();
        $this->liprW2W = new LiprW2W();
        $this->convert = new CurrencyConverter();
    }

    public function getMilestoneBusinessInfo() // dashboard method
    {
        $userId   = Auth::id();
        $business = Services::where('user_id', $userId)
            ->where('category', '!=', 'project_management')->get();

        $milestones = Smilestones::where('user_id', $userId)->latest()->get();

        $nameMap = $business->pluck('name', 'id');
        $milestones->each(fn($m) => $m->service_name = $nameMap[$m->listing_id] ?? '');

        return response()->json(['business' => $business, 'milestones' => $milestones]);
    }

    public function getMilestones(int $id)
    {
        $userId  = Auth::id();
        $service = Services::findOrFail($id);

        $booking = ServiceBooking::where('service_id', $id)
            ->where('booker_id', $userId)
            ->where('status', 'confirmed')
            ->latest()->first();

        $booked     = (bool) $booking;
        $isPaid     = $booked && $booking->paid == 1;
        $milestones = $booked
            ? ServiceBookingMilestone::where('service_id', $id)->where('booker_id', $userId)->get()
            : Smilestones::where('listing_id', $id)->get();

        try {
            foreach ($milestones as $mile) {
                if ($booked) {
                    $deadline        = Carbon::parse($mile->deadline);
                    $mile->time_left = now()->gt($deadline) ? 'L A T E' : now()->diffForHumans($deadline, true);

                    $bookingDate     = Carbon::parse($booking->date)->startOfDay();
                    $mile->deadline  = str_replace('before', '', now()->diffForHumans($bookingDate, [
                        'parts' => 2, 'short' => false, 'absolute' => true,
                    ]));
                } else {
                    $mile->time_left = $mile->n_o_days . ' days, 0 hours, 0 minutes';
                    $mile->deadline  = null;
                }
            }

            $allDone  = $milestones->isNotEmpty() && $milestones->where('status', 'Done')->count() === $milestones->count();
            $reviews  = ServiceReviews::with('user')->where('listing_id', $id)->get()
                ->each(fn($r) => $r->user_image = $r->user?->image);

            return response()->json([
                'data'             => $milestones,
                'service_fee'      => $service->price,
                'booked'           => $booked,
                'isPaid'           => $isPaid,
                'allow'            => $allDone,
                'done_msg'         => $allDone,
                'reviews'          => $reviews,
                'payment_deadline' => $booked ? $milestones->first()?->deadline : null,
            ], 200);

        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function deleteMilestone($id){
        Smilestones::where('id',$id)->delete();
        return response()->json(['message' => 'Milestone deleted successfully.'], 200);
    }

    // GET /services/bBQhdsfE_WWe4Q-_f7ieh7Hdhf4F_-{id}: the provider's services and the milestones of service {id};
    // with "all", the milestones of every service of theirs. With list parameters (page, search, filters, sort, export),
    // "all" is the Service Milestones table: one row per service with its milestone counts and total.
    public function milestones(Request $request, string $id)
    {
        $userId = Auth::id();
        if ($id === 'all' && ServiceLists::wanted($request)) {
            return (new ServiceLists((int) $userId))->milestoneServices($request);
        }

        $business = Services::where('user_id', $userId)->get();

        $milestones    = $id === 'all'
            ? Smilestones::whereIn('listing_id', $business->pluck('id'))->get()
            : Smilestones::where('listing_id', $id)->get();
        $business_name = $id === 'all' ? 'Select Service' : (Services::find($id)?->name ?? '');

        return response()->json([
            'milestones'    => $milestones,
            'business'      => $business,
            'business_name' => $business_name,
        ]);
    }


    // S T O R E
    public function saveMilestone(Request $request)
    {
        $uploadedFiles = [];

        try {
            $validated = $request->validate([
                'title'       => 'required|string|max:255',
                'business_id' => 'required|integer|exists:services,id',
                'amount'      => 'required|integer|min:1',
                'file'        => 'nullable|file|mimes:pdf,docx|max:2048',
                'n_o_days'    => 'required|integer|min:1',
                'time_type'   => 'nullable|string|in:Days,Weeks,Months',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        }

        // Only the provider who owns the service adds its milestones
        $service = Services::where('id', $validated['business_id'])->where('user_id', Auth::id())->first();
        if (! $service) {
            return response()->json(['message' => 'You can only add milestones to your own service.'], 403);
        }

        $covered = Smilestones::where('listing_id', $validated['business_id'])->sum('amount') + $validated['amount'];
        if ($covered > $service->price) {
            return response()->json(['message' => 'The amount exceeds the service price.'], 422);
        }

        DB::beginTransaction();
        try {
            $noDays = match($validated['time_type'] ?? 'Days') {
                'Weeks'  => $validated['n_o_days'] * 7,
                'Months' => $validated['n_o_days'] * 30,
                default  => $validated['n_o_days'],
            };

            // First milestone is active, subsequent ones are on hold
            $hasExisting = Smilestones::where('listing_id', $validated['business_id'])->exists();
            $status      = $hasExisting ? 'On Hold' : 'To Do';

            $finalFile = null;
            if ($request->hasFile('file')) {
                $finalFile       = $this->fileUpload->saveFile($request->file('file'), 'files/Smilestones/' . $validated['business_id']);
                $uploadedFiles[] = $finalFile;
            }

            Smilestones::create([
                'title'      => $validated['title'],
                'amount'     => $validated['amount'],
                'user_id'    => Auth::id(),
                'listing_id' => $validated['business_id'],
                'n_o_days'   => $noDays,
                'document'   => $finalFile,
                'status'     => $status,
            ]);

            DB::commit();
            return response()->json(['message' => 'Milestone created successfully.'], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            foreach ($uploadedFiles as $file) {
                if ($file && file_exists(public_path($file))) unlink(public_path($file));
            }
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * Old email link "Review Milestone" (GET reviewMilestoneS/{rep}/{booker}). Links in emails must never change
     * anything: it opens the booking's milestones panel, where the signed-in customer approves or asks for changes.
     */
    public function reviewMilestone(string $repId, string $bookerId)
    {
        return $this->openMilestonesPanel($repId);
    }

    public function setMilestoneStatus(Request $request)
    {
        try {
            $validated = $request->validate([
                'id'     => 'required|integer|exists:service_booking_milestones,id',
                'status' => 'required|string',
                // Marking it done: what was delivered (optional), shown to the customer before they approve it
                'note'    => 'nullable|string|max:2000',
                'files'   => 'nullable|array|max:5',
                'files.*' => 'file|mimes:pdf,doc,docx,xls,xlsx,csv,ppt,pptx,jpg,jpeg,png,zip|max:10240',
            ]);

            $mile = ServiceBookingMilestone::findOrFail($validated['id']);
            // Only the provider of a paid booking moves its milestones, in order (ServiceBookingFlow)
            try {
                $bookingOfMile = ServiceBookingFlow::assertProviderStep($mile, Auth::user(), $validated['status']);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], in_array($e->getCode(), [404, 409], true) ? $e->getCode() : 422);
            }
            $update = ['status' => $validated['status'], 'active' => $validated['status'] === 'In Progress' ? 1 : 0];
            if ($validated['status'] === 'Done') {
                $files = [];
                foreach ($request->file('files', []) as $file) {
                    $files[] = ['name' => $file->getClientOriginalName(), 'path' => $this->fileUpload->saveFile($file, 'files/service-deliveries/' . $mile->booking_id)];
                }
                // each delivery replaces the one before (after a change request the provider sends the new work)
                $update += ['delivery_note' => $validated['note'] ?? null, 'delivery_files' => $files ?: null, 'delivered_at' => now()];
            }
            $mile->update($update);

            // Starting a milestone tells nobody; marking it done asks the customer to approve it
            if ($validated['status'] === 'In Progress') {
                return response()->json(['message' => 'Milestone started.'], 200);
            }

            $notLastMile = ServiceBookingMilestone::where('booking_id', $mile->booking_id)
                ->where('id', '!=', $mile->id)
                ->where(fn ($q) => $q->whereNull('released')->orWhere('released', 0))->exists();

            $business = Services::findOrFail($mile->service_id);
            $booking  = $bookingOfMile;

            $owner    = User::findOrFail($booking->service_owner_id);
            $customer = User::findOrFail($mile->booker_id);

            [$view, $text] = $notLastMile
                ? ['milestoneS.milestone_mail_done', 'Milestone ' . $mile->title . ' has been marked as done by the Service Owner.']
                : ['milestoneS.last_mile',           'Final milestone ' . $mile->title . ' has been marked as done and the Service is completed.'];

            $serviceLink = ServiceBookingFlow::customerLinkKey($booking);

            $this->notification->create($customer->id, $owner->id, $text . ' Review it to release its payment.', $serviceLink, 'service');

            $this->emailService->send('Milestone Done', $view, [
                'name'       => $mile->title,
                'amount'     => $mile->amount,
                'business'   => $business->name,
                's_id'       => $business->id,
                'booker_id'  => $mile->booker_id,
                'owner'      => $owner->first_name . ' ' . $owner->last_name,
                'rep_id'     => $validated['id'],
                'link'       => ServiceBookingFlow::customerLink($booking),
            ], $customer->email);

            return response()->json(['message' => 'Status updated and notification sent.'], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    /**
     * GET services/milestones/running: the signed-in provider's milestones running now, across every customer: on
     * paid bookings, not released yet, in progress or done and waiting for the customer's approval. Soonest due first.
     */
    public function running()
    {
        $rows = ServiceBookingMilestone::query()
            ->join('service_bookings as b', 'b.id', '=', 'service_booking_milestones.booking_id')
            ->join('services as s', 's.id', '=', 'service_booking_milestones.service_id')
            ->leftJoin('users as u', 'u.id', '=', 'b.booker_id')
            ->where('b.service_owner_id', Auth::id())
            ->where('b.paid', 1)
            ->whereIn('service_booking_milestones.status', ['In Progress', 'Done'])
            ->where(fn ($q) => $q->whereNull('service_booking_milestones.released')->orWhere('service_booking_milestones.released', 0))
            ->orderByRaw('service_booking_milestones.deadline IS NULL, service_booking_milestones.deadline ASC')
            ->limit(100)
            ->get([
                'service_booking_milestones.id', 'service_booking_milestones.title', 'service_booking_milestones.amount',
                'service_booking_milestones.status', 'service_booking_milestones.deadline', 'service_booking_milestones.change_note',
                'service_booking_milestones.n_o_days', 'service_booking_milestones.booking_id', 'service_booking_milestones.service_id',
                'b.booker_id', 's.name as service_name', 'u.first_name', 'u.last_name', 'u.email', 'u.image',
            ])
            ->map(fn ($m) => [
                'id' => $m->id,
                'title' => $m->title,
                'amount' => (float) $m->amount,
                'status' => $m->status,
                'waiting_approval' => $m->status === 'Done',
                'deadline' => $m->deadline,
                'days' => $m->n_o_days ? (int) $m->n_o_days : null,
                'change_note' => $m->change_note,
                'booking_id' => $m->booking_id,
                'service_id' => $m->service_id,
                'service_name' => $m->service_name,
                'booker_id' => $m->booker_id,
                'customer_name' => trim(($m->first_name ?? '').' '.($m->last_name ?? '')) ?: $m->email,
                'customer_image' => $m->image,
            ])->values();

        return response()->json(['data' => $rows]);
    }

    public function findMilestones(int $serviceId, int $bookerId)
    {
        $service = Services::find($serviceId);
        $booker  = User::find($bookerId);

        $milestones = ServiceBookingMilestone::where('service_id', $serviceId)
            ->where('booker_id', $bookerId)->get();

        $business = Services::where('user_id', Auth::id())->get();

        return response()->json([
            'milestones'   => $milestones,
            'business'     => $business,
            's_name'       => $service?->name ?? 'N/A',
            'booker_name'  => $booker ? $booker->first_name . ' ' . $booker->last_name : 'N/A',
        ]);
    }


    /**
     * Old email link "Confirm and Proceed" / "Release Final Payment" (GET agreeToMileS/{rep}/{booker}). It used to
     * release the milestone's payment to anyone holding the link; it now only opens the milestones panel, where the
     * signed-in customer approves the milestone (ServiceBookingFlow::approve).
     */
    public function agreeToProgressWithServiceMilestone($rep_id, $booker_id)
    {
        return $this->openMilestonesPanel((string) $rep_id);
    }

    private function openMilestonesPanel(string $repId)
    {
        $id = (int) explode('.', (string) base64_decode($repId, true))[0];
        $booking = $id ? ServiceBookingMilestone::find($id)?->booking : null;

        return redirect()->away($booking
            ? (ServiceBookingFlow::customerLink($booking) ?? \App\Service\Notification\RecipientUrl::main())
            : \App\Service\Notification\RecipientUrl::main());
    }
}
