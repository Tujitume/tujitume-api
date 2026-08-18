<?php

namespace App\Http\Controllers\Service;

use App\Events\ChatNotification;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Equipments;
use App\Models\Auth\User;
use App\Models\Services\ServiceBooking;
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

    public function storeService(Request $request, SpamWordChecker $spam, SpamImageChecker $spamI)
    {
        $uploadedFiles = [];

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'name'                 => 'required|string|max:255',
                'image'                 => 'required|file|mimes:jpg,jpeg,png,webp|max:2048',
                'identification'        => 'required|file|mimes:pdf,docx|max:2048',
                'document'              => 'required|file|mimes:pdf,docx|max:2048',
                'pin'                   => 'required|file|mimes:pdf,docx|max:2048',
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
            if (!$spam->check($validated['title'], $validated['details'])) {
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
                ...$validated,
                'name'    => $validated['title'],
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
                'document'       => 'nullable|file|mimes:pdf,docx|max:2048',
                'video'          => 'nullable|file|mimes:mp4,mov,avi,webm,mpg,mpeg,wmv|max:51200',
                'link'           => 'nullable|string|url',
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
            $data    = $request->except(['_token', 'link', 'created_at', 'updated_at', 'id']);

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


    //Rating
    public function serviceRating(Request $request)
    {
        try {
            $validated = $request->validate([
                'id'     => 'required|integer|exists:services,id',
                'rating' => 'required|integer|between:1,5',
                'text'   => 'nullable|string|max:1000',
            ]);

            $service = Services::findOrFail($validated['id']);
            $service->increment('rating', $validated['rating']);
            $service->increment('rating_count');

            ServiceReviews::create([
                'user_id'    => Auth::id(),
                'listing_id' => $validated['id'],
                'user_name'  => Auth::user()->fname,
                'text'       => mb_convert_encoding($validated['text'] ?? '', 'UTF-8', 'UTF-8'),
                'rating'     => $validated['rating'],
            ]);

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

            $results = Services::withCount('liked')
                ->where('active', 1)
                ->latest()->take(15)->get()
                ->each(function ($listing) use ($userId) {
                    if (strlen($listing->location) > 30) {
                        $listing->location = substr($listing->location, 0, 30) . '...';
                    }
                    $listing->price  = number_format($listing->price);
                    $listing->file   = null;
                    $listing->liked  = $userId ? $listing->liked()->where('user_id', $userId)->exists() : false;
                });

            return response()->json(['data' => $results], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
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
                $listing->lat    = (float) $listing->lat;
                $listing->lng    = (float) $listing->lng;
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
