<?php

namespace App\Http\Controllers\Service;

use App\Events\ChatNotification;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Equipments;
use App\Models\Auth\User;
use App\Models\Services\ServiceBooking;
use App\Service\Services\ServiceMatcher;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\ServiceMessages;
use App\Models\Services\ServiceReviews;
use App\Models\Services\Services;
use App\Models\Services\Smilestones;
use App\Service\File\ImageCompressor;
use App\Service\Misc\ErrorLogService;
use App\Service\Misc\LocationService;
use App\Service\Notification\NotificationService;
use App\Service\Validation\SpamImageChecker;
use App\Service\Validation\SpamWordChecker;
use App\Service\Validation\UrlValidator;
use Carbon\Carbon;
use DB;
use Exception;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Response;
use Session;

class ServiceController extends Controller
{

    protected $api_base_url;
    public $notification;
    public function __construct()
    {
        parent::__construct();
        //$this->middleware('business');
    }

    /**
     * A provider can only list a service once their identity is verified and they can be paid
     * (a Tujitume wallet or a completed Stripe payout account). Returns the reason, or null.
     */
    private function listingBlockedReason(?User $user): ?array
    {
        if (!$user) return ['code' => 'unauthenticated', 'message' => 'Please sign in to create a service.'];

        $kycStatus = $user->currentKycVerification()->value('status');
        if ($kycStatus !== 'verified') {
            return ['code' => 'kyc_required', 'message' => 'Verify your account (KYC) before you create a service.'];
        }

        $hasWallet = !empty($user->lipr_wallet);
        $hasStripe = !empty($user->connect_id) && $user->connect_id !== 'null' && $user->completed_onboarding;
        if (!$hasWallet && !$hasStripe) {
            return ['code' => 'payment_setup_required', 'message' => 'Set up a payment method before you create a service.'];
        }

        return null;
    }

    /**
     * The provider business details from the Add / Edit Service form (legal name, registration,
     * tax PIN, address, licence flags). On edit, a field that is not sent keeps its saved value.
     */
    private function providerBusinessDetails(Request $request, ?Services $service = null): array
    {
        $request->validate([
            'business_legal_name'  => 'nullable|string|max:255',
            'legal_structure'      => 'nullable|string|max:100',
            'registration_number'  => 'nullable|string|max:100',
            'registration_country' => 'nullable|string|max:100',
            'tax_pin'              => 'nullable|string|max:100',
            'country'              => 'nullable|string|max:100',
            'region'               => 'nullable|string|max:150',
            'physical_address'     => 'nullable|string|max:300',
        ]);

        $fields = ['business_legal_name', 'legal_structure', 'registration_number', 'registration_country', 'tax_pin', 'country', 'region', 'physical_address'];
        $details = [];
        foreach ($fields as $field) {
            if ($request->has($field)) $details[$field] = $request->input($field) ?: null;
            elseif (!$service) $details[$field] = null;
        }
        foreach (['operates_through_business', 'requires_professional_licence'] as $flag) {
            // FormData sends "true" / "false"; boolean() reads both
            if ($request->has($flag) || !$service) $details[$flag] = $request->boolean($flag);
        }

        return $details;
    }

    public function storeService(Request $request, SpamWordChecker $spam, SpamImageChecker $spamI)
    {
        if ($blocked = $this->listingBlockedReason(Auth::user())) {
            return response()->json($blocked + ['status' => 403], 403);
        }

        $uploadedFiles = [];

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'name'                 => 'required|string|max:255',
                'image'                 => 'required|file|mimes:jpg,jpeg,png,webp|max:2048',
                'identification'        => 'required|file|mimes:pdf,docx|max:2048',
                // proof of registration only when the provider operates through a registered business
                'document'              => ($request->boolean('operates_through_business') ? 'required' : 'nullable') . '|file|mimes:pdf,docx,jpg,jpeg,png|max:2048',
                'pin'                   => 'required|file|mimes:pdf,docx,jpg,jpeg,png|max:2048',
                'professional_licence'  => ($request->boolean('requires_professional_licence') ? 'required' : 'nullable') . '|file|mimes:pdf,docx,jpg,jpeg,png|max:2048',
                'video'                 => 'nullable|file|mimes:mp4,mov,avi,webm,mpg,mpeg,wmv|max:51200',
                'link'                  => 'nullable|string|url',
                'price'                 => 'required|integer',
                'category'              => 'required|string|max:155',
                'sub_category'          => 'nullable|string|max:155',
                'details'               => 'required|string|max:1000',
                'location'              => 'required|string|max:300',
                'lat'                   => 'nullable|string|max:100',
                'lng'                   => 'nullable|string|max:100',
                'social_impact_areas'   => 'nullable|array',
                'social_impact_areas.*' => 'string|max:255',
                'business_sector_focus' => 'nullable|array',
            ]);

            // Spam checks
            if (!$spam->check($validated['name'], $validated['details'])) {
                return response()->json(['message' => 'Inappropriate language detected.'], 422);
            }

            $unsafeImageParams = $spamI->check($request->file('image')->getRealPath());
            if (isset($unsafeImageParams['error'])) {
                return response()->json(['message' => $unsafeImageParams['message']], 422);
            }
            if (array_intersect(['violence', 'gore', 'offensive', 'recreational_drug', 'weapon', 'nudity'], $unsafeImageParams)) {
                return response()->json(['message' => 'Inappropriate image detected.'], 422);
            }

            if (!$request->hasFile('video') && $request->filled('link')) {
                if (!(new UrlValidator())->checkValidity($request->link)) {
                    return response()->json(['message' => 'Video URL is invalid or unreachable.'], 422);
                }
            }

            $listing = Services::create([
                ...collect($validated)->except(['image', 'identification', 'document', 'pin', 'professional_licence', 'video', 'link'])->all(),
                ...$this->providerBusinessDetails($request),
                // a typed (not picked) place has no coordinates: store none rather than the column's old London default
                'lat'     => $validated['lat'] ?? null,
                'lng'     => $validated['lng'] ?? null,
                'name'    => $validated['name'],
                'user_id' => Auth::id(),
            ]);

            $fileDir = 'files/services/' . $listing->id;

            $savedImage = $this->imageUpload->save($request->file('image'), 'images/services');
            $uploadedFiles[] = $savedImage;

            $saveFile = function ($key) use ($request, $fileDir, &$uploadedFiles) {
                if (!$request->hasFile($key)) return null;
                $path = $this->fileUpload->saveFile($request->file($key), $fileDir);
                $uploadedFiles[] = $path;
                return $path;
            };

            $listing->update([
                'image'          => $savedImage,
                'pin'            => $saveFile('pin'),
                'identification' => $saveFile('identification'),
                'document'       => $saveFile('document'),
                'professional_licence' => $saveFile('professional_licence'),
                'video'          => $request->hasFile('video') ? $saveFile('video') : $request->link,
            ]);

            // Auto-create milestone for project management services
            if (in_array($validated['category'], ['0', 'project_management'])) {
                Smilestones::create([
                    'user_id'    => Auth::id(),
                    'title'      => 'Transaction Assessment, Management & Transfer',
                    'listing_id' => $listing->id,
                    'amount'     => $validated['price'],
                    'n_o_days'   => 365,
                    'status'     => 'To Do',
                ]);
            }

            DB::commit();
            return response()->json(['message' => 'Service created successfully.'], 200);

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

    public function updateService(Request $request, SpamWordChecker $spam, SpamImageChecker $spamI)
    {
        $uploadedFiles = [];

        try {
            $validated = $request->validate([
                'id'             => 'required|integer|exists:services,id',
                'name'           => 'required|string|max:255',
                'details'        => 'required|string|max:1000',
                'image'          => 'nullable|file|mimes:jpg,jpeg,png,webp|max:2048',
                'pin'            => 'nullable|file|mimes:pdf,docx|max:2048',
                'identification' => 'nullable|file|mimes:pdf,docx|max:2048',
                'document'       => 'nullable|file|mimes:pdf,docx,jpg,jpeg,png|max:2048',
                'professional_licence' => 'nullable|file|mimes:pdf,docx,jpg,jpeg,png|max:2048',
                'video'          => 'nullable|file|mimes:mp4,mov,avi,webm,mpg,mpeg,wmv|max:51200',
                'link'           => 'nullable|string|url',
                'price'          => 'sometimes|numeric|min:0|max:1000000000',
                'category'       => 'sometimes|string|max:155',
                'sub_category'   => 'nullable|string|max:155',
                'location'       => 'sometimes|string|max:300',
                'lat'            => 'nullable|string|max:100',
                'lng'            => 'nullable|string|max:100',
                'social_impact_areas'   => 'nullable|array',
                'social_impact_areas.*' => 'string|max:255',
                'business_sector_focus' => 'nullable|array',
            ]);

            $service = Services::where('id', $validated['id'])
                ->where('user_id', Auth::id())
                ->firstOrFail();

            if (!$spam->check($validated['name'], $validated['details'])) {
                return response()->json(['message' => 'Inappropriate language detected.'], 422);
            }

            if ($request->hasFile('image')) {
                $unsafeImageParams = $spamI->check($request->file('image')->getRealPath());
                if (isset($unsafeImageParams['error'])) {
                    return response()->json(['message' => $unsafeImageParams['message']], 422);
                }
                if (array_intersect(['violence', 'gore', 'offensive', 'recreational_drug', 'weapon', 'nudity'], $unsafeImageParams)) {
                    return response()->json(['message' => 'Inappropriate image detected.'], 422);
                }
            }

            if (!$request->hasFile('video') && $request->filled('link')) {
                if (!(new UrlValidator())->checkValidity($request->link)) {
                    return response()->json(['message' => 'Video URL is invalid or unreachable.'], 422);
                }
            }

            $fileDir = 'files/services/' . $service->id;
            // Only the service's own columns: anything else in the form used to reach the UPDATE and fail it
            $data = [
                ...collect($validated)->only(['name', 'details', 'price', 'category', 'sub_category', 'location', 'lat', 'lng', 'social_impact_areas', 'business_sector_focus'])->all(),
                ...$this->providerBusinessDetails($request, $service),
            ];

            $replaceFile = function ($key, $oldPath) use ($request, $fileDir, &$data, &$uploadedFiles) {
                if (!$request->hasFile($key)) return;
                $path = $this->fileUpload->saveFile($request->file($key), $fileDir);
                $uploadedFiles[] = $path;
                $data[$key] = $path;
                if ($oldPath && file_exists(public_path($oldPath))) unlink(public_path($oldPath));
            };

            if ($request->hasFile('image')) {
                $path = $this->imageUpload->save($request->file('image'), 'images/services');
                $uploadedFiles[] = $path;
                $data['image'] = $path;
                if ($service->image && file_exists(public_path($service->image))) unlink(public_path($service->image));
            }

            $replaceFile('pin',            $service->pin);
            $replaceFile('identification', $service->identification);
            $replaceFile('document',       $service->document);
            $replaceFile('professional_licence', $service->professional_licence);
            $replaceFile('video',          $service->video);

            if ($request->filled('link')) $data['video'] = $request->link;

            $service->update($data);

            return response()->json(['message' => 'Service updated successfully.'], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            foreach ($uploadedFiles as $file) {
                if ($file && file_exists(public_path($file))) unlink(public_path($file));
            }
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function deleteService(int $id)
    {
        try {
            $service = Services::where('id', $id)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            File::deleteDirectory(public_path('files/services/' . $id));
            File::deleteDirectory(public_path('files/Smilestones/' . $id));

            Smilestones::where('listing_id', $id)->delete();
            $service->delete();

            return response()->json(['message' => 'Service deleted successfully.'], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['id' => $id]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function activate(Request $request)
    {
        try {
            $validated = $request->validate([
                'id' => 'required|integer|exists:services,id',
            ]);

            $service = Services::where('id', $validated['id'])
                ->where('user_id', Auth::id())
                ->firstOrFail();

            if (!Smilestones::where('listing_id', $validated['id'])->exists()) {
                return response()->json(['message' => 'A service must have at least one milestone before activation.'], 400);
            }
            // The customer pays the service price and gets it back to the provider milestone by milestone: the milestones
            // must add up to the price, or part of what the customer paid is never released to the provider
            $covered = (float) Smilestones::where('listing_id', $validated['id'])->sum('amount');
            if (abs($covered - (float) $service->price) >= 0.01) {
                return response()->json(['message' => 'The milestones must add up to the service price (KES '.number_format((float) $service->price)
                    .'). They add up to KES '.number_format($covered).' now: add or change a milestone, then activate.'], 422);
            }

            $service->update(['active' => 1]);
            return response()->json(['message' => 'Service activated successfully.'], 200);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }
    public function deactivate(Request $request)
    {
        try {
            $validated = $request->validate([
                'id' => 'required|integer|exists:services,id',
            ]);

            Services::where('id', $validated['id'])
                ->where('user_id', Auth::id())->firstOrFail()
                ->update(['active' => 0]);

            return response()->json(['message' => 'Service deactivated successfully.'], 200);
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
     * Rate a service (GET services/ratingService/{id}/{rating}/{text}): 1 to 5 stars and a comment.
     * services.rating is the AVERAGE out of 5 (what every list and card shows) and rating_count how many rated it, so
     * a new rating moves the average: (average × count + stars) / (count + 1). It used to add the stars onto the
     * average (a 3.8 average plus 5 stars read 8.8). A row already holding a total above 5 is read as total / count,
     * so it heals on its next rating. The values may come in the path or as query/body input.
     */
    public function serviceRating(Request $request)
    {
        try {
            $request->merge(array_filter([
                'id'     => $request->input('id', $request->route('id')),
                'rating' => $request->input('rating', $request->route('rating')),
                'text'   => $request->input('text', is_string($request->route('text')) ? trim($request->route('text')) : null),
            ], fn ($value) => $value !== null && $value !== ''));
            $validated = $request->validate([
                'id'     => 'required|integer|exists:services,id',
                'rating' => 'required|integer|between:1,5',
                'text'   => 'nullable|string|max:1000',
            ]);

            $stars = (int) $validated['rating'];
            DB::transaction(function () use ($validated, $stars) {
                $service = Services::lockForUpdate()->findOrFail($validated['id']);
                $count = max(0, (int) $service->rating_count);
                $stored = (float) $service->rating;
                // An older row may hold the sum of every rating instead of the average
                $average = $stored > 5 ? ($count > 0 ? $stored / $count : 5.0) : $stored;
                $average = max(0.0, min(5.0, $average));
                $service->forceFill([
                    'rating' => round((($average * $count) + $stars) / ($count + 1), 2),
                    'rating_count' => $count + 1,
                ])->save();

                ServiceReviews::create([
                    'user_id'    => Auth::id(),
                    'listing_id' => $validated['id'],
                    'user_name'  => Auth::user()->first_name,
                    'text'       => mb_convert_encoding($validated['text'] ?? '', 'UTF-8', 'UTF-8'),
                    'rating'     => $stars,
                ]);
            });

            return response()->json(['success' => 'Success', 200]);

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // f e a t u r e d
    public function featuredServices()
    {
        try {
            $userId  = Auth::id();
            // ?all=1 (Explore services): every live service, with who offers it and its milestones for the details
            // panel. Without it, the latest 15 as before (landing page and matches)
            $all = request()->boolean('all');
            // a cancelled or rejected booking does not count: the service can be booked again (serviceBook allows it)
            $booked = $userId ? ServiceBooking::where('booker_id', $userId)->whereNotIn('status', ['rejected', 'cancelled'])->pluck('service_id')->flip() : collect();

            $query = Services::withCount('liked')->where('active', 1)->latest();
            if ($all) {
                $query->with([
                    'owner:id,first_name,last_name,image,city,country',
                    'milestones:id,listing_id,title,amount,n_o_days',
                ]);
            }

            $results = ($all ? $query->get() : $query->take(15)->get())
                ->each(function ($listing) use ($userId, $all, $booked) {
                    if (! $all && strlen($listing->location) > 30) {
                        $listing->location = substr($listing->location, 0, 30) . '...';
                    }
                    $listing->price  = number_format($listing->price);
                    $listing->file   = null;
                    $listing->liked  = $userId ? $listing->liked()->where('user_id', $userId)->exists() : false;
                    $listing->booked = $booked->has($listing->id);
                    // the provider's identity / registration documents are never public
                    $listing->makeHidden(['pin', 'identification', 'document']);
                    if ($all) {
                        $owner = $listing->owner;
                        $listing->provider_name  = $owner ? trim($owner->first_name . ' ' . $owner->last_name) : null;
                        $listing->provider_image = $owner?->image;
                        // Only the name and picture of the provider are shown; nothing else of the account is sent
                        $listing->unsetRelation('owner');
                        $listing->makeHidden(['pin', 'identification', 'document', 'video']);
                    }
                });

            // Explore services: how well each suits the person (their interests, role, sectors, ratings), best first
            if ($all) {
                $results = ServiceMatcher::sort(ServiceMatcher::score($results, Auth::user()));
            }

            return response()->json(['data' => $results], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    /**
     * POST services/{service}/viewed {seconds}: the person looked at a service's details for this long (the Explore
     * Services panel). Kept per person and service, so their recommendations follow what they spend time on.
     */
    public function recordView(Request $request, int $service)
    {
        $seconds = (int) min(600, max(0, (int) $request->input('seconds', 0)));
        if (! Services::whereKey($service)->where('active', 1)->exists()) {
            return response()->json(['message' => 'Service not found.'], 404);
        }
        $user = Auth::user();
        $row = DB::table('service_views')->where('user_id', $user->id)->where('service_id', $service)->first();
        if ($row) {
            DB::table('service_views')->where('id', $row->id)->update([
                'views' => $row->views + 1, 'seconds' => min(86400, $row->seconds + $seconds),
                'organization_id' => $user->organization_id, 'last_viewed_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            DB::table('service_views')->insert([
                'user_id' => $user->id, 'service_id' => $service, 'organization_id' => $user->organization_id,
                'views' => 1, 'seconds' => $seconds, 'last_viewed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Noted.']);
    }

    public function serviceResultsByIds(string $ids)
    {
        try {
            $userId  = Auth::id();
            $results = [];

            foreach (explode(',', $ids) as $id) {
                if ($id === '' || $id === '0' || $id === 'no-results') continue;

                $listing = Services::withCount('liked')->find($id);
                if (!$listing) continue;

                $listing->liked  = $userId ? $listing->liked()->where('user_id', $userId)->exists() : false;
                $listing->price  = number_format($listing->price);
                $listing->lat    = $listing->lat !== null ? (float) $listing->lat : null;
                $listing->lng    = $listing->lng !== null ? (float) $listing->lng : null;
                $listing->booked = $userId && ServiceBooking::where('service_id', $id)
                    ->where('booker_id', $userId)
                    ->exists() ? 1 : 0;

                $results[] = $listing;
            }

            return response()->json(['data' => $results, 'count' => count($results)], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['ids' => $ids]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

}
