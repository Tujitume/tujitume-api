<?php

namespace App\Http\Controllers\Account;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\admin;
use App\Models\Auth\User;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalOffer;
use App\Models\Capital\CapitalProfile;
use App\Models\Capital\StartupPitches;
use App\Models\Grants\Grant;
use App\Models\Grants\GrantApplication;
use App\Models\Grants\GrantProfile;
use App\Models\Services\ServiceBookingMilestone;
use App\Models\Services\Smilestones;
use App\Service\Account\CalculateUserFunds;
use App\Service\Misc\ErrorLogService;
use App\Service\Misc\GetPlaces;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            switch ($user->user_type_id){
                case 1: // Investor
                    //return $this->getInvestorDashboard($user);

                case 2: // Grant
                    return response()->json($funds->calculateGrantFunds($user), 200);

                case 3: // Capital
                    return response()->json($funds->calculateCapitalFunds($user), 200);

                case 4: // Business - SME
                    return response()->json($funds->calculateSMEFunds($user), 200);

                case 5: // Service Provider
                    //return $funds->calculateServiceProviderFunds($user);

                case 6: // Internal Reviewer
                    //return $this->getInternalReviewerDashboard($user);

                case 7: // External Reviewer
                    //return $this->getExternalReviewerDashboard($user);

                default:
                    return response()->json([
                        'user' => $user,
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
                $user = GrantProfile::with('user')
                    ->where('grant_owner_id', $user->id)
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
                $grantProfile = $user->grant_profile;

                if ($grantProfile && in_array($grantProfile->role_id, [10001, 10002, 10003])) {
                    $grantProfile->update(['active' => 1]);
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


}
