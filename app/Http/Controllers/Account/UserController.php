<?php

namespace App\Http\Controllers\Account;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\admin;
use App\Models\Auth\User;

use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Smilestones;
use App\Service\Account\CalculateUserFunds;
use App\Service\Misc\ErrorLogService;
use App\Service\Misc\GetPlaces;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\User\UserResource;
use App\Service\Account\AccountDeletionEligibilityService;
use App\Models\Business\AcceptedBids;
use App\Models\Business\BusinessBids;
use App\Models\Business\BusinessSubscriptions;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\CapitalProfile;
use App\Models\Communication\Messages;
use App\Models\Finance\BalanceLog;
use App\Models\Organizations\Organization;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramProfile;
use App\Models\Misc\Setting;
use App\Models\Services\ServiceBooking;
use App\Models\Services\ServiceMessages;
use App\Models\Services\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use Session;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */

    public function index()
    {
        try{
            $users = User::orderBy('id','desc')->get();
            return response()->json([ 'users' => $users ], 200);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        if (!$user){
            return response()->json(['status' => 400, 'message' => 'User not found.'], 404);
        }
        $authId = Auth::id();

        try {

            $data = $user->only(['email','id','fname','lname','gender','image']);
            $data['from_id'] = $authId;
            $data['to_id'] = $user->id;
            $data['sender'] = "{$user->fname} {$user->lname}";
            $data['messages'] = [];
            $data['service_id'] = 0;

            return response()->json(['user' => $data, 'status' => 200]);

        } catch (\Exception $e) {
            ErrorLogService::report($e, [ 'input' => request()->except(['password', 'token']), ]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function update(Request $request, User $user)
    {
    //
    }

    public function me(CalculateUserFunds $funds) {
        $user = Auth::user();

        try{
            if (!$user) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            $user->loadMissing('settings', 'user_type');

            switch ($user->user_type_id){
                case 1: // Business Owner
                    return response()->json($funds->calculateSMEFunds($user), 200);

                case 2: // Investor
                    return response()->json($funds->calculateProgramFunds($user), 200);

                case 3: // Service Provider
                    return response()->json($funds->calculateCapitalFunds($user), 200);

                case 4: // Org
                    return response()->json([
                        'data' => new UserResource($user)
                    ], 200);

                case 5: // Service Provider
                    //return $funds->calculateServiceProviderFunds($user);

                case 6: // Internal Reviewer
                    //return $this->getInternalReviewerDashboard($user);

                case 7: // External Reviewer
                    //return $this->getExternalReviewerDashboard($user);

                default:
                    return response()->json([
                        'user' => new UserResource($user),
                        'role' => 'N/A',
                        'total_funds' => 0,
                        'available_funds' => 0,
                    ], 200);
            }
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

    public function RoleBasedUsers()
    {
        try{
            $user = Auth::user();
            if ($user->user_type_id == 2) {
                $user = ProgramProfile::with('user')
                    ->where('program_owner_id', $user->id)
                    ->latest()->get();
            } else {
                $user = CapitalProfile::with('user')
                    ->where('capital_owner_id', $user->id)
                    ->latest()->get();
            }
            return response()->json([ 'users' => $user], 200);
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

    public function checkEmailExists(string $email)
    {
        try {
            $exists = User::where('email', $email)->exists();

            if ($exists) {
                return response()->json(['status' => 400, 'message' => 'Email already exists.'], 400);
            }

            return response()->json(['status' => 200], 200);

        } catch (\Exception $e) {
            ErrorLogService::report($e, [ 'input' => request()->except(['password', 'token']), ]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function resetPassword(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string|min:6', // optional: add password_confirmation
            ]);

            $user = User::where('email', $validated['email'])->first();

            if (!$user) {
                return response()->json(['status' => 400, 'message' => 'User does not exist'], 400);
            }

            $user->password = bcrypt($validated['password']);
            $success = $user->save();

            // Activating role based users
            if($user->user_type_id == 2){
                $programProfile = $user->program_profile;

                if ($programProfile && in_array($programProfile->role_id, [10001, 10002, 10003])) {
                    $programProfile->update(['active' => 1]);
                }
            }

            $message = $success ? 'Password reset Success!' : 'Password reset Failed!';
            return response()->json(['message' => $message], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            ErrorLogService::report($e, [ 'input' => request()->except(['password', 'token']), ]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function partiesInfo($listing_id) {
        $listing = listing::select('user_id')->where('id', $listing_id)->first();

        $owner = User::select('email')->where('id', $listing->user_id)->first();

        return response()->json([
            'user' => Auth::user(),
            'owner' => $owner
        ]);
    }

    public function getServiceOwner($repMileId)
    {
        try {
            $milestoneBooking = ServiceBookingMilestone::find($repMileId);
            if (!$milestoneBooking) {
                return response()->json(['message' => 'Milestone booking not found.'], 404);
            }

            $milestone = Smilestones::select('id', 'user_id')->find($milestoneBooking->mile_id);
            if (!$milestone) {
                return response()->json(['message' => 'Milestone not found.'], 404);
            }

            $owner = User::select('paystack_acc_id')->find($milestone->user_id);
            if (!$owner) {
                return response()->json(['message' => 'Owner not found.'], 404);
            }

            $owner->true_mile_id = $milestone->id;

            return response()->json([
                'user' => Auth::user(),
                'owner' => $owner,
            ]);

        } catch (\Exception $e) {
            ErrorLogService::report($e, [ 'input' => request()->except(['password', 'token']), ]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    // Maybe unused
    public function updateProfile(Request $request)
    {
        $uploadedFiles = [];

        try {
            $validated = $request->validate([
                'fname'  => 'nullable|string|max:100',
                'lname'  => 'nullable|string|max:100',
                'mname'  => 'nullable|string|max:100',
                'dob'    => 'nullable|date',
                'gender' => 'nullable|string|in:Male,Female,other',
                'image'  => 'nullable|file|mimes:jpg,jpeg,png,webp|max:2048',
            ]);

            $user = Auth::user();
            $data = collect($validated)->except('image')->filter()->all();

            if ($request->hasFile('image')) {
                $newImage      = $this->imageUpload->save($request->file('image'), 'images/users');
                $uploadedFiles[] = $newImage;
                $data['image'] = $newImage;

                if ($user->image && file_exists(public_path($user->image))) {
                    unlink(public_path($user->image));
                }
            }

            $user->update($data);

            return response()->json(['status' => 200, 'message' => 'Profile updated successfully.'], 200);

        } catch (Exception $e) {
            foreach ($uploadedFiles as $file) {
                if ($file && file_exists(public_path($file))) unlink(public_path($file));
            }
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }


    // Delete account
    public function destroy($id)
    {
        $user = User::with('balance')->find($id);

        if (!$user) {
            return response(['message' => 'User not found.'], 404);
        }

        $type = $user->user_type_id;

        if ($id != Auth::id()) {
            return response(['message' => 'Unauthorized.'], 401);
        }

        if ($user->balance?->balance > 0) {
            return response(['message' => 'Account deletion not allowed. Please withdraw your balance first.'], 400);
        }

        //deletion eligibility checks
        $checker = new AccountDeletionEligibilityService($user);

        if (!$checker->isDeletable()) {
            return response()->json([
                'message' => 'Account deletion is not allowed',
                'reasons' => $checker->preventingReason(),
            ], 400);
        }

        //return response(['message' => 'Deletion begins...'],200);

        DB::beginTransaction();
        try {

            //Balance logs
            BalanceLog::where('changed_by', $user->id)->delete();
            $this->deleteProgramDataForUser($user);

            if ($type == 1) {
                // Delete all investor files & active investment
                BusinessBids::where('investor_id', $id)->delete();
                AcceptedBids::where('investor_id', $id)->delete();

                ServiceBooking::where('booker_id', $id)->delete();
                ServiceMessages::where('to_id', $id)->orWhere('from_id', $id)->delete();

                if ($user->id_passport) {
                    $this->deleteLocalFile($user->id_passport);
                }

                if ($user->pin) {
                    $this->deleteLocalFile($user->pin);
                }
            } else if ($type == 2) {
                ServiceBooking::where('booker_id', $id)->delete();
                ServiceMessages::where('to_id', $id)->orWhere('from_id', $id)->delete();
            } else if ($type == 3) {
                // Delete all Capital owner files & Capital profile
                $cap = CapitalProfile::where('user_id', $id)->first();
                if ($cap && $cap->document) {
                    $this->deleteLocalFile($cap->document);
                }
                $cap?->delete();
                $capitals = CapitalOffer::where('user_id', $id)->get();
                foreach ($capitals as $capital) {
                    $this->deleteLocalFile($capital->offer_brief_pdf);
                    $capital->delete();
                }

                ServiceBooking::where('booker_id', $id)->delete();
                ServiceMessages::where('to_id', $id)->orWhere('from_id', $id)->delete();
            } else if ($type == 4 || $type == 5) {
                //Delete Business owner documents
                ServiceBooking::where('booker_id', $id)->delete();
                ServiceMessages::where('to_id', $id)->orWhere('from_id', $id)->delete();
                Messages::where('to_id', $id)->orWhere('from_id', $id)->delete();

                if ($type == 4) {
                    $this->deleteOrganizationDataForUser($user);
                }

                $listings = Listing::where('user_id', $id)->get();
                $services = Services::where('user_id', $id)->get();
                foreach ($listings as $listing) {
                    $this->deleteLocalFile($listing->pin);
                    $this->deleteLocalFile($listing->identification);
                    $this->deleteLocalFile($listing->document);
                    $this->deleteLocalFile($listing->video);

                    $listing->delete();
                }
                BusinessBids::where('owner_id', $id)->delete();
                AcceptedBids::where('owner_id', $id)
                    ->whereNotIn('status', ['Confirmed', 'awaiting_payment', 'under_verification'])
                    ->delete();

                foreach ($services as $service) {
                    $this->deleteLocalFile($service->pin);
                    $this->deleteLocalFile($service->identification);
                    $this->deleteLocalFile($service->document);
                    $this->deleteLocalFile($service->video);

                    $service->delete();
                }
                ServiceBooking::where('service_owner_id', $id)->delete();
            }

            $this->deleteS3File($user->image);
            $this->deleteLocalFile($user->image);
            User::where('id', $id)->delete();
            DB::commit();
            return response(['message' => 'Account removed. All documents deleted.'], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);

            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    private function deleteOrganizationDataForUser(User $user): void
    {
        $organizationIds = Organization::query()
            ->where('owner_user_id', $user->id)
            ->when($user->organization_id, function ($query) use ($user) {
                $query->orWhere('id', $user->organization_id);
            })
            ->pluck('id');

        if ($organizationIds->isEmpty()) {
            return;
        }

        DB::table('workspaces')
            ->whereIn('organization_id', $organizationIds)
            ->delete();

        Organization::whereIn('id', $organizationIds)->delete();
    }

    private function deleteProgramDataForUser(User $user): void
    {
        $userId = $user->id;
        $programIds = Program::where('user_id', $userId)->pluck('id');

        $applicationIds = ProgramApplication::where(function ($query) use ($userId, $programIds) {
            $query->where('user_id', $userId)
                ->orWhere('program_owner_id', $userId);

            if ($programIds->isNotEmpty()) {
                $query->orWhereIn('program_id', $programIds);
            }
        })->pluck('id');

        $this->deleteProgramApplications($applicationIds->all());
        $this->deleteOwnedProgramProfiles($user);

        Program::where('user_id', $userId)
            ->get()
            ->each(function (Program $program): void {
                $this->deleteLocalFile($program->program_brief_pdf);
                $program->delete();
            });
    }

    private function deleteProgramApplications(array $applicationIds): void
    {
        if (empty($applicationIds)) {
            return;
        }

        $applications = ProgramApplication::whereIn('id', $applicationIds)->get();
        $milestoneIds = DB::table('program_milestones')
            ->whereIn('app_id', $applicationIds)
            ->pluck('id');

        $applications->each(function (ProgramApplication $application): void {
            $this->deleteS3File($application->pitch_deck_file);
            $this->deleteS3File($application->business_plan_file);
            $this->deleteLocalFile($application->pitch_video);
        });

        DB::table('round_required_documents')
            ->whereIn('application_id', $applicationIds)
            ->pluck('file_path')
            ->each(fn (?string $path) => $this->deleteS3File($path));

        DB::table('application_round_responses')
            ->whereIn('application_id', $applicationIds)
            ->pluck('file_path')
            ->each(fn (?string $path) => $this->deleteS3File($path));

        if ($milestoneIds->isNotEmpty()) {
            $this->deleteProgramMilestones($milestoneIds->all());
        }

        ProgramApplication::whereIn('id', $applicationIds)->delete();
    }

    private function deleteProgramMilestones(array $milestoneIds): void
    {
        DB::table('program_milestones')
            ->whereIn('id', $milestoneIds)
            ->pluck('document')
            ->each(fn (?string $path) => $this->deleteS3File($path));

        DB::table('milestone_verifications')
            ->whereIn('milestone_id', $milestoneIds)
            ->pluck('document')
            ->each(fn (?string $path) => $this->deleteS3File($path));

        DB::table('deal_room_documents')
            ->whereIn('milestone_id', $milestoneIds)
            ->pluck('file_path')
            ->each(fn (?string $path) => $this->deleteS3File($path));

        DB::table('disbursements')
            ->whereIn('milestone_id', $milestoneIds)
            ->pluck('receipt_file')
            ->each(fn (?string $path) => $this->deleteS3File($path));

        DB::table('milestone_suppliers')
            ->whereIn('milestone_id', $milestoneIds)
            ->get(['invoice_file', 'quotation_file'])
            ->each(function ($supplier): void {
                $this->deleteS3File($supplier->invoice_file);
                $this->deleteS3File($supplier->quotation_file);
            });

        DB::table('disbursements')->whereIn('milestone_id', $milestoneIds)->delete();
        DB::table('deal_room_documents')->whereIn('milestone_id', $milestoneIds)->delete();
        DB::table('milestone_verifications')->whereIn('milestone_id', $milestoneIds)->delete();
        DB::table('milestone_completion_submissions')->whereIn('milestone_id', $milestoneIds)->delete();
        DB::table('program_milestones')->whereIn('id', $milestoneIds)->delete();
    }

    private function deleteOwnedProgramProfiles(User $user): void
    {
        $profiles = ProgramProfile::with('user')
            ->where('user_id', $user->id)
            ->orWhere('program_owner_id', $user->id)
            ->get();

        foreach ($profiles as $profile) {
            $this->deleteLocalFile($profile->document);

            if ($profile->user_id !== $user->id) {
                BalanceLog::where('changed_by', $profile->user_id)->delete();
                ServiceMessages::where('to_id', $profile->user_id)
                    ->orWhere('from_id', $profile->user_id)
                    ->delete();
                Messages::where('to_id', $profile->user_id)
                    ->orWhere('from_id', $profile->user_id)
                    ->delete();

                $profile->user?->delete();
            }

            $profile->delete();
        }
    }

    private function deleteLocalFile(?string $path): void
    {
        if (!$path) {
            return;
        }

        $filePath = public_path($path);

        if (is_file($filePath)) {
            unlink($filePath);
        }
    }

    private function deleteS3File(?string $path): void
    {
        if (!$path) {
            return;
        }

        $baseUrl = rtrim((string) config('filesystems.disks.s3.url'), '/');
        $key = str_starts_with($path, $baseUrl . '/')
            ? substr($path, strlen($baseUrl) + 1)
            : ltrim((string) parse_url($path, PHP_URL_PATH), '/');

        if ($key) {
            Storage::disk('s3')->delete($key);
        }
    }

}
