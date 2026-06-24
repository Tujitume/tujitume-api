<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Models\Finance\Transactions;
use App\Models\Misc\Setting;
use App\Models\Services\ServiceBook;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\ServiceReviews;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use App\Service\Balance\BalanceService;
use App\Service\Balance\CurrencyConverter;
use App\Service\LiprMpesa\LiprW2W;
use App\Service\Misc\ErrorLogService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $booking = ServiceBook::where('service_id', $id)
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

        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function deleteMilestone($id){
        Smilestones::where('id',$id)->delete();
        return response()->json(['message' => 'Milestone deleted successfully.'], 200);
    }

    public function milestones(string $id)
    {
        $userId   = Auth::id();
        $business = Services::where('user_id', $userId)->get();

        $milestones    = $id === 'all' ? collect() : Smilestones::where('listing_id', $id)->get();
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

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'title'       => 'required|string|max:255',
                'business_id' => 'required|integer|exists:services,id',
                'amount'      => 'required|integer|min:1',
                'file'        => 'nullable|file|mimes:pdf,docx|max:2048',
                'n_o_days'    => 'required|integer|min:1',
                'time_type'   => 'nullable|string|in:Days,Weeks,Months',
            ]);

            $service = Services::where('id', $validated['business_id'])
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $noDays = match($validated['time_type'] ?? 'Days') {
                'Weeks'  => $validated['n_o_days'] * 7,
                'Months' => $validated['n_o_days'] * 30,
                default  => $validated['n_o_days'],
            };

            $covered = Smilestones::where('listing_id', $validated['business_id'])->sum('amount') + $validated['amount'];
            if ($covered > $service->price) {
                return response()->json(['message' => 'The amount exceeds the service price.'], 422);
            }

            // First milestone is active, subsequent ones are on hold
            $hasExisting = Smilestones::where('listing_id', $validated['business_id'])->exists();
            $status      = $hasExisting ? 'On Hold' : 'To Do';

            $finalFile = null;
            if ($request->hasFile('file')) {
                $finalFile       = $this->fileUpload->saveFile($request->file('file'), 'files/Smilestones/' . $validated['business_id']);
                $uploadedFiles[] = $finalFile;
            }

            Smilestones::create([
                ...$validated,
                'user_id'    => Auth::id(),
                'listing_id' => $validated['business_id'],
                'n_o_days'   => $noDays,
                'document'   => $finalFile,
                'status'     => $status,
            ]);

            DB::commit();
            return response()->json(['message' => 'Milestone created successfully.'], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            DB::rollBack();
            foreach ($uploadedFiles as $file) {
                if ($file && file_exists(public_path($file))) unlink(public_path($file));
            }
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function reviewMilestone(string $repId, string $bookerId)
    {
        try {
            $repId    = explode('.', base64_decode($repId))[0];
            $mile  = ServiceBookingMilestone::select('id', 'service_id', 'amount', 'title', 'released')
                ->findOrFail($repId);
            $serv  = Services::select('name', 'id', 'user_id', 'price')->findOrFail($mile->service_id);
            $owner = User::select('fname', 'id', 'connect_id', 'email')->findOrFail($serv->user_id);

            $mile->update(['status' => 'In Progress']);

            $this->notification->create(
                $owner->id, null, 'Milestone ' . $mile->title . ' for service ' . $serv->name . ' is being reviewed by the customer.', '/', 'service'
            );

            $this->emailService->send(
                'Milestone Review', 'milestoneS.milestone_review',
                ['service' => $serv->name, 'milestone' => $mile->title], $owner->email
            );

            return redirect()->to(config('app.app_url') . 'service-milestones/' . base64_encode(base64_encode($serv->id)));

        } catch (Exception $e) {
            ErrorLogService::report($e, ['rep_id' => $repId ?? null]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    public function setMilestoneStatus(Request $request)
    {
        try {
            $validated = $request->validate([
                'id'     => 'required|integer|exists:service_booking_milestones,id',
                'status' => 'required|string',
            ]);

            $mile = ServiceBookingMilestone::findOrFail($validated['id']);
            $mile->update(['status' => $validated['status'], 'active' => 0]);

            $notLastMile = ServiceBookingMilestone::where('service_id', $mile->service_id)
                ->where('booker_id', $mile->booker_id)
                ->where('status', 'To Do')->exists();

            $business = Services::findOrFail($mile->service_id);
            $booking  = ServiceBook::where('service_id', $mile->service_id)
                ->where('booker_id', $mile->booker_id)->latest()->firstOrFail();

            $owner    = User::findOrFail($booking->service_owner_id);
            $customer = User::findOrFail($mile->booker_id);

            [$view, $text] = $notLastMile
                ? ['milestoneS.milestone_mail_done', 'Milestone ' . $mile->title . ' has been marked as done by the Service Owner.']
                : ['milestoneS.last_mile',           'Final milestone ' . $mile->title . ' has been marked as done and the Service is completed.'];

            $serviceLink = 'service-milestones/' . base64_encode(base64_encode($business->id));

            $this->notification->create($customer->id, $owner->id, $text, $serviceLink, 'service');

            $this->emailService->send('Milestone Done', $view, [
                'name'       => $mile->title,
                'amount'     => $mile->amount,
                'business'   => $business->name,
                's_id'       => $business->id,
                'booker_id'  => $mile->booker_id,
                'owner'      => $owner->fname . ' ' . $owner->lname,
                'rep_id'     => $validated['id'],
            ], $customer->email);

            return response()->json(['message' => 'Status updated and notification sent.'], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
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
            'booker_name'  => $booker ? $booker->fname . ' ' . $booker->lname : 'N/A',
        ]);
    }


    // Optional - Email Click Methods
    public function agreeToProgressWithServiceMilestone($rep_id, $booker_id)
    {
        $rep_id = explode('.', base64_decode($rep_id))[0];
        $booker_id = explode('.', base64_decode($booker_id))[0];

        try {
            $milestone = ServiceBookingMilestone::findOrFail($rep_id);
            if ($milestone->released) {
                return 'Payment already released for this milestone!';
            }

            $service = $milestone->service;
            $customer = User::findOrFail($milestone->booker_id);
            $owner = $service->owner;
            $s_id = base64_encode(base64_encode($service->id));
            $transferAmount = round($milestone->amount, 2);

            // Fetch last transaction
            $transaction = Transactions::where([
                'user_id' => $customer->id, 'recipient_id' => $owner->id, 'type' => 'service_fee'
            ])->latest()->first();

            // Release Payment
            if ($transaction->method === 'stripe') {

                if (!$owner->connect_id) return 'Service owner not onboarded to Stripe to receive payment.';
                $transfer = $this->Client->transfers->create([
                    'amount' => $transferAmount * 100,
                    'currency' => 'USD',
                    'destination' => $owner->connect_id
                ]);
                $this->transaction->create(
                    $customer->id, 'service_milestone', 'stripe', $transferAmount, $transfer->id, $owner->id
                );

            } else { // Lipr

                if (!$owner->lipr_wallet) return 'Service owner not onboarded to Lipr.';

                $amountKes = round($this->convert->UsdToKes() * $transferAmount, 2);
                $transfer = $this->liprW2W->send($amountKes, $owner->lipr_wallet, $this->tujitume_lipr, 'Service Milestone Payment');

                if (!$transfer || !$transfer['success']) return 'Milestone release failed: ' . ($transfer['errors'][0] ?? 'Unknown error');
                $this->transaction->create($customer->id, 'service_milestone', 'lipr', $transferAmount, 'N/A', $owner->id);
            }

            $milestone->update(['released' => 1]);

            // Activate next milestone if exists
            $nextMilestone = ServiceBookingMilestone::where('service_id', $milestone->service_id)
                ->where('booker_id', $booker_id)->where('status', 'To Do')->first();
            if ($nextMilestone) $nextMilestone->update(['active' => 1, 'status' => 'In Progress']);

            // Update balance
            $this->balance->updateBalance($owner->id, $transferAmount, 'Unknown');

            // Notifications
            $this->createNotification(
                $owner->id, null, "Milestone payment for {$milestone->title} released", "/service-milestones/{$s_id}", 'service'
            );
            $this->createNotification(
                $booker_id, null, "Milestone payment for {$milestone->title} released. Next milestone in progress", "/service-milestones/{$s_id}", 'service'
            );

            // Check if service completed
            $pending = ServiceBookingMilestone::where('service_id', $milestone->service_id)
                ->where('booker_id', $booker_id)
                ->whereIn('status', ['To Do','In Progress'])
                ->exists();

            $stage = $pending ? "{$milestone->title} Released" : 'Service Delivered';
            ServiceBook::where('id', $milestone->booking_id)->update(['stage' => $stage, 'status' => $pending ? 'In Progress' : 'delivered']);

            // Send emails if service completed
            if (!$pending) {
                $dataCustomer = ['s_id' => $s_id, 'service' => $service->name, 'amount' => $service->price, 'to' => 1, 'user_name' => $customer->fname];
                $dataOwner = ['s_id' => $s_id, 'service' => $service->name, 'amount' => $service->price, 'to' => 2, 'user_name' => $owner->fname];

                $this->emailService->send('Service Done', 'milestoneS.service_done_mail', $dataCustomer, $customer->email);
                $this->emailService->send('Service Done', 'milestoneS.service_done_mail', $dataOwner, $owner->email);
            }

            return redirect()->to(config('app.app_url') . "service-milestones/{$s_id}?payment_released=true");

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password','token'])]);
            return redirect()->to(config('app.app_url'));
        }
    }

}
