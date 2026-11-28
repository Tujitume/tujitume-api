<?php

namespace App\Http\Controllers\Business;



use App\Http\Controllers\Controller;
use App\Models\Business\BusinessDocs;
use App\Models\Capital\CapitalOffer;
use App\Models\Programs\Program;
use App\Service\AI\InvestorPersonalizedListing;
use App\Service\Business\MilestoneOverviewList;
use App\Service\Business\MyListingList;
use App\Support\ListShapes;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use App\Models\Auth\User;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessSubscriptions;
use App\Models\Business\Conversation;
use App\Models\Business\Listing;
use App\Models\Business\Review;
use App\Models\Milestones\Milestones;
use App\Models\Services\Services;

use App\Service\Misc\ErrorLogService;
use App\Service\Validation\SpamImageChecker;
use App\Service\Validation\SpamWordChecker;
use App\Service\Validation\UrlValidator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use DateTime; use DB; use Exception; use Hash; use Mail;
use Response; use Session; use Stripe\StripeClient;

class BusinessController extends Controller
{
    protected $Client;
    public function __construct(StripeClient $client)
    {
        parent::__construct();

        $this->Client = $client;
        //$this->middleware('business');
    }

    public function listings(Request $request){

        // A page with search / filters / sort / export for the My Businesses and Deal Room tables; no params = the full list
        if (ListShapes::wanted($request)) {
            return (new MyListingList((int) Auth::id()))->respond($request);
        }

        $listings = Listing::where('user_id', Auth::id())->latest()->get();

        return response()->json(['business' => $listings]);
    }

    public function featuredListings()
    {
        try {
            $userId     = Auth::id();
            $user       = Auth::user();
            $topListings = null;

            if ($userId && $user->user_type_id == 1) {
                $personalized = new InvestorPersonalizedListing($user);
                $topListings  = $personalized->getTopListings(15);
                $results      = collect($topListings)->pluck('listing')->values()->all();
            } else {
                $specialCategories = ['Agriculture', 'Renewable-Energy'];

                $results = Listing::withCount('liked')
                    ->where('active', 1)->orderByRaw(
                        "CASE WHEN category IN (?, ?) THEN 0 ELSE 1 END, created_at DESC",
                        $specialCategories
                    )
                    ->take(15)->get()
                    ->each(function ($listing) use ($userId) {
                        if (strlen($listing->location) > 30) {
                            $listing->location = substr($listing->location, 0, 30) . '...';
                        }
                        $listing->investment_needed = number_format($listing->investment_needed);
                        $listing->file   = null;
                        $listing->liked  = $userId ? $listing->liked()->where('user_id', $userId)->exists() : false;
                    })
                    ->all();
            }

            $programs   = Program::listedForApplicants()->withCount('liked')->latest()->get();
            $capitals = CapitalOffer::withCount('liked')->where('visible', 1)->latest()->get();

            if ($userId) {
                $programs->each(fn($g) => $g->liked = $g->liked()->where('user_id', $userId)->exists());
                $capitals->each(fn($c) => $c->liked = $c->liked()->where('user_id', $userId)->exists());
            }

            return response()->json([
                'score-data' => $topListings,
                'data'       => $results,
                'programs'     => $programs,
                'capitals'   => $capitals,
            ], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function storeListing(Request $request, SpamWordChecker $spam, SpamImageChecker $spamI)
    {
        if ($request->user()?->currentKycVerification()->value('status') !== 'verified') {
            return response()->json([
                'message' => 'Verify your account (KYC) before you create a business.',
                'code' => 'kyc_required',
            ], 403);
        }

        // Payments run on LIPR: a business cannot be listed until its owner's wallet exists
        $walletOwner = User::find($request->user()->organizationOwnerId());
        if (! $walletOwner?->lipr_wallet) {
            return response()->json([
                'message' => 'Connect your LIPR wallet in your account before creating a business.',
                'code'    => 'lipr_required',
            ], 422);
        }

        $uploadedFiles = [];

        // Same field names as updateListing are accepted too (name, stage, video link)
        $request->merge(array_filter([
            'title'          => $request->input('title', $request->input('name')),
            'business_stage' => $request->input('business_stage', $request->input('stage')),
            'videoLink'      => $request->input('videoLink', is_string($request->input('video')) ? $request->input('video') : null),
        ], fn ($value) => $value !== null));

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'title'               => 'required|string|max:300',
                'category'            => 'required|string|max:255',
                'image'               => 'required|file|mimes:jpg,jpeg,png,webp|max:2048',
                'identification'      => 'required|file|mimes:pdf,docx,jpg,jpeg,png|max:2048', // a photo of the ID, as the form and updateListing allow
                'document'            => 'required|file|mimes:pdf,docx|max:2048',
                'pin'                 => 'required|file|mimes:pdf,docx|max:2048',
                'yeary_fin_statement' => 'nullable|file|mimes:pdf,docx|max:2048',
                'video'               => 'nullable|file|mimes:mp4,mov,avi,webm,mpg,mpeg,wmv|max:51200',
                'videoLink'           => 'nullable|string|url',
                'details'             => 'required|string|max:1500',
                'location'            => 'required|string|max:300',
                'lat'                 => 'nullable|string|max:100',
                'lng'                 => 'nullable|string|max:100',
                'contact'             => 'nullable|string|max:255',
                'contact_mail'        => 'nullable|email|max:100',
                'investment_needed'   => 'nullable|integer',
                'share'               => 'nullable|integer|between:0,100',
                'y_turnover'          => 'required|string|max:255',
                'reason'              => 'nullable|string|max:500',
                'stage'               => 'nullable|string|max:70',
                'social_impact_areas' => 'nullable|array',
                // A fixed KSh amount an investor pays to unlock the business, never a percentage
                'investors_fee'       => 'nullable|integer|min:'.\App\Models\Business\Listing::MIN_INVESTORS_FEE,
                'id_no'               => 'required|string|max:255',
                'tax_pin'             => 'required|string|max:255',
                // Business verification (KYB) details asked by the form
                'business_type'        => 'nullable|string|max:60',
                'registration_number'  => 'nullable|string|max:100',
                'registration_country' => 'nullable|string|max:2',
                'proof_of_address'     => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'business_registration_certificate' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
            ]);
            // Files are saved after the listing exists (below), not as columns from the validated input
            unset($validated['proof_of_address'], $validated['business_registration_certificate']);

            if (!$spam->check($validated['title'], $validated['details'], $validated['reason'] ?? '')) {
                return response()->json(['message' => 'Inappropriate language detected.'], 422);
            }

            $unsafeImageParams = $spamI->check($request->file('image')->getRealPath());

            if (isset($unsafeImageParams['error'])) {
                return response()->json(['message' => $unsafeImageParams['message']], 422);
            }

            if (array_intersect(['violence','gore','offensive','recreational_drug','weapon','nudity'], $unsafeImageParams)) {
                return response()->json(['message' => 'Inappropriate image detected.'], 422);
            }

            if (!$request->hasFile('video') && $request->filled('videoLink')) {
                if (!(new UrlValidator())->checkValidity($request->videoLink)) {
                    return response()->json(['message' => 'Video URL is invalid or unreachable.'], 422);
                }
            }

            $validated['video'] = $validated['videoLink'] ?? null;
            unset($validated['videoLink']);

            $validated['name']   = $validated['title'];
            unset($validated['title']);

            $listing = Listing::create([
                ...$validated,
                'user_id' => Auth::id(),
                'stage'   => $request->business_stage,
                'social_impact_areas' => $validated['social_impact_areas'] ?? [],
            ]);

            $fileDir = 'files/business/' . $listing->id;

            $savedImage = $this->imageUpload->save($request->file('image'), 'images/listing');
            $uploadedFiles[] = $savedImage;

            $saveFile = function ($key) use ($request, $fileDir, &$uploadedFiles) {
                if (!$request->hasFile($key)) return null;
                $path = $this->fileUpload->saveFile($request->file($key), $fileDir);
                $uploadedFiles[] = $path;
                return $path;
            };

            $listing->update([
                'image'               => $savedImage,
                'pin'                 => $saveFile('pin'),
                'identification'      => $saveFile('identification'),
                'document'            => $saveFile('document'),
                'yeary_fin_statement' => $saveFile('yeary_fin_statement'),
                'proof_of_address'    => $saveFile('proof_of_address'),
                'business_registration_certificate' => $saveFile('business_registration_certificate'),
                'video'               => $request->hasFile('video') ? $saveFile('video') : $request->videoLink,
            ]);

            DB::commit();
            return response()->json(['message' => 'Business listing created successfully.'], 200);

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


    public function updateListing(Request $request, SpamWordChecker $spam, SpamImageChecker $spamI)
    {
        $uploadedFiles = [];

        // Same field names as storeListing are accepted too (title, business_stage, videoLink)
        $request->merge(array_filter([
            'name'  => $request->input('name', $request->input('title')),
            'stage' => $request->input('stage', $request->input('business_stage')),
            'link'  => $request->input('link', $request->input('videoLink')),
        ], fn ($value) => $value !== null));

        try {
            $validated = $request->validate([
                'id'                   => 'required|integer|exists:listings,id',
                'name'                 => 'required|string|max:300',
                'details'              => 'required|string|max:1500',
                'reason'               => 'sometimes|nullable|string|max:500',
                'category'             => 'sometimes|required|string|max:255',
                'location'             => 'sometimes|required|string|max:300',
                'lat'                  => 'nullable|string|max:100',
                'lng'                  => 'nullable|string|max:100',
                'contact'              => 'nullable|string|max:255',
                'contact_mail'         => 'nullable|email|max:100',
                'stage'                => 'nullable|string|max:70',
                'investors_fee'        => 'nullable|integer|min:'.\App\Models\Business\Listing::MIN_INVESTORS_FEE,
                'social_impact_areas'  => 'nullable|array',
                'business_type'        => 'nullable|string|max:60',
                'registration_number'  => 'nullable|string|max:100',
                'registration_country' => 'nullable|string|max:2',
                'link'                 => 'nullable|string|url',
                'image'                => 'nullable|file|mimes:jpg,jpeg,png,webp|max:2048',
                'pin'                  => 'nullable|file|mimes:pdf,docx|max:2048',
                'identification'       => 'nullable|file|mimes:pdf,docx,jpg,jpeg,png|max:2048',
                'document'             => 'nullable|file|mimes:pdf,docx|max:2048',
                'yeary_fin_statement'  => 'nullable|file|mimes:pdf,docx|max:2048',
                'proof_of_address'     => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'business_registration_certificate' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'video'                => 'nullable|file|mimes:mp4,mov,avi,webm,mpg,mpeg,wmv|max:51200',
            ]);

            // Only the business owner (or their organization) may change it
            $ownerIds = array_unique([Auth::id(), $request->user()->organizationOwnerId()]);
            $listing = Listing::where('id', $validated['id'])->whereIn('user_id', $ownerIds)->first();
            if (! $listing) {
                return response()->json(['message' => 'Business not found.'], 404);
            }

            if (!$spam->check($validated['name'], $validated['details'], $validated['reason'] ?? '')) {
                return response()->json(['message' => 'Inappropriate language detected.'], 422);
            }

            if ($request->hasFile('image')) {
                $unsafeImageParams = $spamI->check($request->file('image')->getRealPath());
                if (isset($unsafeImageParams['error'])) {
                    return response()->json(['message' => $unsafeImageParams['message']], 422);
                }
                if (array_intersect(['violence','gore','offensive','recreational_drug','weapon','nudity'], $unsafeImageParams)) {
                    return response()->json(['message' => 'Inappropriate image detected.'], 422);
                }
            }

            if (!$request->hasFile('video') && $request->filled('link')) {
                if (!(new UrlValidator())->checkValidity($request->link)) {
                    return response()->json(['message' => 'Video URL is invalid or unreachable.'], 422);
                }
            }

            $fileDir = 'files/business/' . $listing->id;

            // Only these fields can change after creation. investment_needed, share, y_turnover, id_no and
            // tax_pin stay read-only; anything else in the request (user_id, active, amount_collected…) is ignored.
            $editable = [
                'name', 'category', 'details', 'location', 'lat', 'lng', 'contact', 'contact_mail', 'stage',
                'investors_fee', 'social_impact_areas', 'reason', 'business_type', 'registration_number', 'registration_country',
            ];
            $data = Arr::only($validated, array_filter($editable, fn ($key) => $request->has($key)));

            $replaceFile = function ($key, $oldPath) use ($request, $fileDir, &$data, &$uploadedFiles) {
                if (!$request->hasFile($key)) return;
                $path = $this->fileUpload->saveFile($request->file($key), $fileDir);
                $uploadedFiles[] = $path; $data[$key] = $path;
                if ($oldPath && file_exists(public_path($oldPath))) unlink(public_path($oldPath));
            };

            if ($request->hasFile('image')) {
                $path = $this->imageUpload->save($request->file('image'), 'images/listing');
                $uploadedFiles[] = $path; $data['image'] = $path;

                if ($listing->image && file_exists(public_path($listing->image))) unlink(public_path($listing->image));
            }

            $replaceFile('pin',                               $listing->pin);
            $replaceFile('identification',                    $listing->identification);
            $replaceFile('document',                          $listing->document);
            $replaceFile('yeary_fin_statement',               $listing->yeary_fin_statement);
            $replaceFile('proof_of_address',                  $listing->proof_of_address);
            $replaceFile('business_registration_certificate', $listing->business_registration_certificate);
            $replaceFile('video',                             $listing->video);

            if ($request->filled('link')) $data['video'] = $request->link;

            $listing->update($data);

            return response()->json(['message' => 'Business updated successfully.'], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            foreach ($uploadedFiles as $file) {
                if ($file && file_exists(public_path($file))) unlink(public_path($file));
            }
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // show
    /** Listing fields only the business owner may read: identity numbers and compliance documents */
    public const PRIVATE_LISTING_FIELDS = [
        'identification', 'id_no', 'tax_pin', 'document', 'proof_of_address', 'business_registration_certificate',
        'yeary_fin_statement', 'registration_number', 'pin',
    ];

    public function listing($id)
    {
        try {

            if (is_numeric($id)) {
                $listing = Listing::withCount('liked')->findOrFail($id);
            } else {
                $listing = Listing::withCount('liked')
                    ->where('public_id', $id)
                    ->firstOrFail();
            }


            $files   = businessDocs::where('business_id', $id)->where('media', 1)->first();

            $countMilestones = 0;
            $allowToReview   = false;
            $isInvested      = false;
            $isInvestor      = false;
            $isUnlocked      = false;
            $running         = 0;

            if (Auth::check()) {
                $user   = Auth::user();
                $userId = $user->id;

                $listing->liked = $listing->liked()->where('user_id', $userId)->exists();

                $milestones      = $listing->milestones;
                $countMilestones = $milestones->count();
                $allDone         = $milestones->every(fn($m) => $m->status === 'done');
                $isInvested      = $listing->accepted_bids()->where('investor_id', $userId)->exists();
                $isInvestor      = (int) $user->user_type_id === 2; // 2 = investor (1 is the business owner)
                $allowToReview   = $allDone && $isInvested;
                $running         = (int) $milestones->contains('active', true);

                $isUnlocked = $listing->conversations()
                    ->where('investor_id', $userId)
                    ->where('active', 1)->exists();
            }

            $listing->file    = $files->file ?? false;
            $amountRequired   = $listing->investment_needed - $listing->amount_collected;

            // The owner's identity and compliance papers are for the owner only (this endpoint is public)
            if ((int) Auth::id() !== (int) $listing->user_id) {
                $listing->makeHidden(self::PRIVATE_LISTING_FIELDS);
            }

            return response()->json([
                'data'           => $listing,
                'length'         => $countMilestones,
                'isInvestor'     => $isInvestor,
                'isInvested'     => $isInvested,
                'running'        => $running,
                'conv'           => $isUnlocked,
                'allowToReview'  => $allowToReview,
                'amount_required'=> $amountRequired,
            ], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['id' => $id]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    // listing details more info
    public function listingDetailsInfo(int $listingId) // for listing details
    {
        $investorId  = Auth::id();
        $isInvestor  = (int) User::where('id', $investorId)->value('user_type_id') === 2; // 2 = investor
        $listing     = Listing::find($listingId);
        $investorsFee = $listing?->investors_fee;

        $conv = Conversation::where('investor_id', $investorId)
            ->where('listing_id', $listingId)->where('active', 1)->exists();

        $reviews = Review::where('listing_id', $listingId)->get()
            ->each(fn($r) => $r->user_image = $r->user?->image);

        $sub = BusinessSubscriptions::where('investor_id', $investorId)
            ->where('active', 1)->latest()->first();

        $results = []; $count   = 0;

        if ($sub) {
            try {
                $stripeSub = $this->Client->subscriptions->retrieve($sub->stripe_sub_id);
            } catch (Exception $e) {
                ErrorLogService::report($e, ['sub_id' => $sub->stripe_sub_id]);
                return response()->json([
                    'data' => ['subscribed' => 0], 'conv' => $conv,
                    'fee' => $investorsFee, 'count' => 0,
                    'reviews' => $reviews, 'isInvestor' => $isInvestor,
                    'error' => 'Something went wrong, please try again later.',
                ], 500);
            }

            if ($sub->plan === 'platinum' || $sub->plan === 'platinum-trial') $conv = true;

            $expireDate = Carbon::createFromTimestamp($stripeSub->current_period_end);
            $daysLeft   = now()->diffInDays($expireDate, false);
            $monLeft    = now()->diffInMonths($expireDate, false);

            if ($daysLeft <= 0 && $monLeft == 0) {
                Conversation::where('listing_id', $listingId)->where('investor_id', $investorId)->update(['active' => 0]);
            } else {
                $count = 1;
                $results = [
                    'subscribed'    => 1,
                    'sub_id'        => $sub->id,
                    'stripe_sub_id' => $sub->stripe_sub_id,
                    'trial'         => $sub->trial,
                    'expire'        => $daysLeft == 0 && $monLeft >= 1 ? 30 : $daysLeft,
                    'token_left'    => $sub->token_remaining,
                    'range'         => $sub->chosen_range,
                    'plan'          => $sub->plan,
                    'amount'        => $sub->amount,
                    'end_date'      => $expireDate->format('Y-m-d'),
                ];
            }
        }

        return response()->json([
            'data'       => $results, 'fee'        => $investorsFee,
            'conv'       => $conv,    'count'      => $count,
            'reviews'    => $reviews, 'isInvestor' => $isInvestor,
        ]);
    }

    public function deleteListing(int $id)
    {
        try {
            $listing = Listing::findOrFail($id);

            if($listing->user_id !== Auth::id()){
                return response()->json(['message' => 'Unauthorized!'], 401);
            }

            if($listing->accepted_bids()->count() > 0 || $listing->bids()->count() > 0){
                return response()->json([
                    'message' => 'Cannot delete business with active or pending investments.'
                ], 422);
            }

            File::deleteDirectory(public_path('files/business/' . $id));
            File::deleteDirectory(public_path('files/milestones/' . $id));

            $listing->delete(); // booted() handles cascade
            $listing->milestones()->delete(); // Ensure milestones are deleted



            return response()->json(['message' => 'Business deleted successfully.'], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['id' => $id]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }



    public function activateListing(int $id)
    {
        try {
            $listing = Listing::where('id', $id)->where('user_id', Auth::id())->first();
            if (! $listing) {
                return response()->json(['message' => 'Business not found.'], 404);
            }

            if (!$listing->share || !$listing->investment_needed) {
                return response()->json([
                    'message' => 'Listing must have equity share & investment amount before activation.'
                ], 422);
            }

            $milestonesTotal = $listing->milestones()->sum('amount');

            if ((int) $milestonesTotal !== (int) $listing->investment_needed) {
                return response()->json([
                    'message' => 'Milestones must cover the full investment amount before activation.'
                ], 422);
            }

            $listing->update(['active' => 1]);

            return response()->json(['message' => 'Business activated successfully.'], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, ['id' => $id]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function useMilestoneBusinessInfo(Request $request){

        // The Milestones table: one row per business with its milestone progress, searched / filtered / paged on the server
        if (ListShapes::wanted($request)) {
            return (new MilestoneOverviewList((int) Auth::id()))->respond($request);
        }

        $milestones = Milestones::where('user_id',Auth::id())->latest()->get();
        $business = listing::where('user_id',Auth::id())->get();

        foreach($business as $b) {
            foreach ($milestones as $m) {
                if ($m->listing_id == $b->id) {
                    $m->business_name = $b->name;
                }
            }
        }

        return response()->json([
            'business' => $business, 'milestones' => $milestones
        ], 200);
    }

    // ratings
    /**
     * Rate a business (GET ratingListing/{id}/{rating}/{text}): 1 to 5 stars and a comment.
     * listings.rating is the AVERAGE out of 5 and rating_count how many rated it, so a new rating moves the average:
     * (average × count + stars) / (count + 1). It used to add the stars onto the average (3.8 + 5 read 8.8). A row
     * already holding a total above 5 is read as total / count, so it heals on its next rating. The values may come in
     * the path or as query/body input. Same rule as ServiceController::serviceRating.
     */
    public function ratingListing(Request $request)
    {
        try {
            $request->merge(array_filter([
                'id'     => $request->input('id', $request->route('id')),
                'rating' => $request->input('rating', $request->route('rating')),
                'text'   => $request->input('text', is_string($request->route('text')) ? trim($request->route('text')) : null),
            ], fn ($value) => $value !== null && $value !== ''));
            $validated = $request->validate([
                'id'     => 'required|integer|exists:listings,id',
                'rating' => 'required|integer|between:1,5',
                'text'   => 'nullable|string|max:1000',
            ]);

            $stars = (int) $validated['rating'];
            DB::transaction(function () use ($validated, $stars) {
                $listing = Listing::lockForUpdate()->findOrFail($validated['id']);
                $count = max(0, (int) $listing->rating_count);
                $stored = (float) $listing->rating;
                // An older row may hold the sum of every rating instead of the average
                $average = $stored > 5 ? ($count > 0 ? $stored / $count : 5.0) : $stored;
                $average = max(0.0, min(5.0, $average));
                $listing->forceFill([
                    'rating' => round((($average * $count) + $stars) / ($count + 1), 2),
                    'rating_count' => $count + 1,
                ])->save();

                Review::create([
                    'user_id'    => Auth::id(),
                    'listing_id' => $validated['id'],
                    'user_name'  => Auth::user()->first_name,
                    'text'       => mb_convert_encoding($validated['text'] ?? '', 'UTF-8', 'UTF-8'),
                    'rating'     => $stars,
                ]);
            });

            return response()->json(['success' => 'Success!']);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    public function unlockBusiness(Request $request, $id = null, $sub_id = null, $plan = null)
    {
        // The route is unlockBySubs/{id}/{sub_id}/{plan}: read those (it used to validate an empty body and always fail)
        $listingId = (int) ($id ?? $request->input('listing_id'));
        $subId = (int) ($sub_id ?? $request->input('sub_id'));

        $listing = Listing::find($listingId);
        if (!$listing) {
            return response()->json(['message' => 'Business not found.'], 404);
        }

        // Already unlocked: the same answer, no second token used
        if (Conversation::where('investor_id', Auth::id())->where('listing_id', $listing->id)->exists()) {
            return response()->json(['message' => 'success', 'already_unlocked' => true], 200);
        }

        // The subscription must be the person's own (or their organisation's) and still running; each unlock uses one
        // of the plan's unlocks, whatever plan name the browser sends
        $subscription = BusinessSubscriptions::where('id', $subId)
            ->where('investor_id', \App\Service\Subscription\SubscriptionHolder::idFor(Auth::user()))
            ->where('expire_date', '>', now())->first();
        if (!$subscription) {
            return response()->json(['message' => 'You need an active subscription to unlock this business.'], 403);
        }
        if ((int) $subscription->token_remaining <= 0) {
            return response()->json(['message' => 'You have used all the business unlocks in your plan. Upgrade or pay the unlock fee instead.'], 422);
        }

        try {
            DB::transaction(function () use ($subscription, $listing) {
                $subscription->decrement('token_remaining');
                Conversation::create([
                    'investor_id' => Auth::id(),
                    'listing_id'  => $listing->id,
                    'price'       => 'Subscription',
                ]);
            });

            return response()->json(['message' => 'success', 'unlocks_left' => (int) $subscription->refresh()->token_remaining], 200);
        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function deactivate(Request $request)
    {
        try {
            $validated = $request->validate([
                'id' => 'required|integer|exists:listings,id',
            ]);

            Listing::where('id', $validated['id'])->where('user_id', Auth::id())
                ->firstOrFail()->update(['active' => 0]);

            return response()->json(['message' => 'Business deactivated successfully.'], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function listingResultsByIds(string $ids)
    {
        try {
            if ($ids === '0') {
                return response()->json(['data' => [], 'count' => 0]);
            }
            $userId  = Auth::id(); $results = []; $ranges  = [];

            foreach (explode(',', $ids) as $id) {
                if ($id === '') continue;

                $listing = Listing::withCount('liked')->find($id);
                if (!$listing) continue;

                if ($userId) {
                    $listing->liked = $listing->liked()->where('user_id', $userId)->exists();
                }

                $mediaFile     = businessDocs::where('business_id', $id)->where('media', 1)->first();
                $listing->file = $mediaFile->file ?? false;

                $listing->lat = (float) $listing->lat;
                $listing->lng = (float) $listing->lng;

                $range    = explode('-', $listing->y_turnover);
                $ranges[] = (int) ($range[1] ?? $range[0] ?? 0);

                $results[] = $listing;
            }
            rsort($ranges);

            return response()->json([
                'data'      => $results,
                'count'     => count($results),
                'max_range' => $ranges[0] ?? 0,
            ]);
        } catch (Exception $e) {
            ErrorLogService::report($e, ['ids' => $ids]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function findProjectManagers(int $bidId)
    {
        try {
            $bid     = AcceptedBids::findOrFail($bidId);
            $listing = $bid->listing;
            $services = $this->findNearestServices((float) $listing->lat, (float) $listing->lng, 100);

            return response()->json([
                'results' => $services,
                'loc'     => true,
                'lat' => (float) $listing->lat, 'lng' => (float) $listing->lng,
            ], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['bid_id' => $bidId]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function findProjectManagersforBusiness(int $businessId)
    {
        try {
            $listing = Listing::findOrFail($businessId);
            $services = $this->findNearestServices((float) $listing->lat, (float) $listing->lng, 100);

            return response()->json([
                'results' => $services,
                'loc'     => true,
                'lat' => (float) $listing->lat,
                'lng' => (float) $listing->lng,
            ], 200);
        } catch (Exception $e) {
            ErrorLogService::report($e, ['business_id' => $businessId]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    // Private Helper
    private function findNearestServices($latitude, $longitude, $radius = 100)
    {
        $listings = Services::selectRaw("* ,
                         ( 3956 * acos( cos( radians(?) ) *
                           cos( radians( lat ) )
                           * cos( radians( lng ) - radians(?)
                           ) + sin( radians(?) ) *
                           sin( radians( lat ) ) )
                         ) AS distance", [$latitude, $longitude, $latitude])
            ->with([
            'owner:id,first_name,last_name,email,image'
            ])
            ->whereIn('category', ['project_management', 'business_coaching'])
            ->having("distance", "<", $radius)
            ->orderBy("distance",'asc')
            ->offset(0)
            ->limit(20)
            ->get()
            ->each(
                function ($listing) {
                    if (strlen($listing->location) > 30) {
                        $listing->location = substr($listing->location, 0, 30) . '...';
                    };
                });


        return $listings;
    }

}
