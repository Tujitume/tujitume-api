<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Auth\User;
use App\Http\Resources\User\UserResource;

use App\Models\EmailVerificationCode;
use App\Service\Account\DeviceVerificationService;
use App\Service\Account\RegisterService;
use App\Service\Misc\ErrorLogService;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Mail;

class AuthController extends Controller
{
    public function sendEmailVerification(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $email = $request->email;
        $code = random_int(1000, 9999);

        // Remove previous unused codes
        EmailVerificationCode::where('email', $email)
            ->whereNull('verified_at')->delete();

        // Save hashed code
        $record = EmailVerificationCode::create([
            'email' => $email,
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
        ]);

        // Send email
        Mail::send('verify_mail', ['code' => $code], function ($msg) use ($email) {
            $msg->to($email)->subject('Email Verification');
        });

        return response()->json([
            'message' => 'Verification code sent'
        ], 200);
    }

    public function verifyEmailCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required'
        ]);

        $record = EmailVerificationCode::where('email', $request->email)
            ->whereNull('verified_at')->latest()->first();

        if (!$record) {
            return response()->json(['verified' => false, 'error' => 'No verification request found'], 404);
        }

        // Check expiry
        if (now()->greaterThan($record->expires_at)) {
            return response()->json(['verified' => false, 'error' => 'Code expired'], 422);
        }

        if ($record->attempts >= 5) {
            return response()->json(['verified' => false, 'error' => 'Too many attempts'], 429);
        }

        // Check code
        if (!Hash::check($request->code, $record->code)) {
            $record->increment('attempts');
            return response()->json(['verified' => false, 'error' => 'Invalid code'], 422);
        }

        // Mark verified
        $record->update([ 'verified_at' => now() ]);

        return response()->json([
            'verified' => true,
            'message' => 'Email verified successfully'
        ], 200);
    }


    public function login(LoginRequest $request)
    {
        if(!$request->browserLoginCheck)
        return response([
                'error' => '401! Unauthorized.',
            ],401);

        $data = $request->validated();

        if(!Auth::attempt($data)){
            $user = User::where('email', $data['email'])->first();

            $msg = $user ? 'Incorrect password.' : 'No account found with this email.';

            return response([
                'message' => $msg, 'auth' => Auth::check()
            ], 200);
        }

        $user = Auth::user();

        // D e v i c e   V e r i f i c a t i o n
        $testingNow = false;

        if($testingNow){
            $deviceService = new DeviceVerificationService();
            $verification = $deviceService->createVerification($user, $request);
        }

        //for special user
        $specialEmail = 'agrisokoo@gmail.com';

        if($data['email'] == $specialEmail){
            $expiresAt = now()->addMonths(6); // addMonths(6)
            $token = $user->createToken('main', ['*'], $expiresAt)->plainTextToken;
        }
        else
        {
            $token = $user->createToken('main')->plainTextToken;
        }

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
            'auth' => Auth::check()
        ]);

    }

    public function verifyDevice(Request $request, DeviceVerificationService $deviceService)
    {
        $verify = $deviceService->verify($request);
        return $verify;
    }

    public function register(RegisterRequest $request, RegisterService $regService )
    {
        $request->validate([
            'user_type_id' => ['required', 'integer', 'in:1,2,3,4,5'],
        ]);

        if(isset($request->user_type_id) && $request->user_type_id == 1)
        {
            //$data = $request->all();
            $data = $request->validate([
                //Required Fields
                'fname' => ['required', 'string', 'max:255'],
                'lname' => ['required', 'string', 'max:255'],
                'gender' => ['required', 'in:Male,Female,Other'],
                'dob' => ['required', 'date'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
                'id_passport' => [
                    'required',
                    'file',
                    'mimes:pdf,docx,jpg,jpeg,png,gif,webp',
                    'mimetypes:image/*,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'max:2048',
                ],
                'pin' => [
                    'required',
                    'file',
                    'mimes:pdf,docx,jpg,jpeg,png,gif,webp',
                    'mimetypes:image/*,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'max:2048',
                ],
                // Arrays (casted in model)
                'inv_range' => ['required', 'array'],
                'turnover_range' => ['required', 'array'],
                'interested_cats' => ['required', 'array'],
                'stage' => ['required', 'array'],
                'social_impact_areas' => ['nullable', 'array', 'max:500'],
                'regions_focus' => ['required', 'array'],
                'id_no' => ['nullable', 'string', 'max:255'],
                'tax_pin' => ['nullable', 'string', 'max:255'],
                //Required Fields

                // Misc
                'mname' => ['nullable', 'string', 'max:255'],
                'user_type_id' => ['required', 'integer', 'in:1,2,3,4,5'],
                //'user_type_id' => ['nullable', 'integer'],
                'website' => ['nullable', 'url', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'past_investment' => ['nullable', 'string', 'max:1000'],
            ]);

            $register = $regService->investorRegister($data);
            return $register;
        }

        // G R A N T
        if(isset($request->user_type_id) && $request->user_type_id == 2)
        {

            if ($request->has('role_id')) {
                $data = $request->validate([
                    'role_id' => ['required', 'nullable', 'integer'], // optional, integer
                    'email' => ['required', 'email', 'max:255'],
                    'fname' => ['required', 'string', 'max:255'],
                    'user_type_id' => ['required', 'integer', 'in:1,2,3,4,5'],
                    'grant_owner_id' => ['required', 'integer'],
                ]);
                $register = $regService->grantRoleUserRegister($data);
                return $register;
            }

            $data = $request->validate([
                //Required Fields
                'fname' => ['required', 'string', 'max:255'],
                //'lname' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
                // Arrays (casted in model)
                'interested_cats' => ['required', 'array'],
                'stage' => ['required', 'array'],
                'social_impact_areas' => ['nullable', 'array', 'max:500'],
                'regions' => ['required', 'array'],
                'org_type' => ['required', 'string', 'max:255'],
                //Required Fields

                // Misc
                'user_type_id' => ['required', 'integer', 'in:1,2,3,4,5'],
                'website' => ['nullable', 'url', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'mission' => ['nullable', 'string', 'max:1000'],
                'document' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:2048'],
                //For adding role based users
                'role_id' => ['nullable', 'integer'], // optional, integer
                'grant_owner_id' => ['nullable', 'integer'],
            ]);
            $register = $regService->grantRegister($data);
            return $register;
        }

        // C A P I T A L
        if(isset($request->user_type_id) && $request->user_type_id == 3)
        {
            if ($request->has('role_id')) {
                $data = $request->validate([
                    'role_id' => ['required', 'nullable', 'integer'], // optional, integer
                    'email' => ['required', 'email', 'max:255'],
                    'fname' => ['required', 'string', 'max:255'],
                    'user_type_id' => ['required', 'integer', 'in:1,2,3,4,5'],
                    'capital_owner_id' => ['required', 'integer'],
                ]);
                $register = $regService->capitalRoleUserRegister($data);
                return $register;
            }

            $data = $request->validate([
                //Required Fields
                'fname' => ['required', 'string', 'max:255'],
                //'lname' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
                // Arrays (casted in model)
                'inv_range' => ['required', 'array'],
                'turnover_range' => ['required', 'array'],
                'interested_cats' => ['required', 'array'],
                'stage' => ['required', 'array'],
                'social_impact_areas' => ['nullable', 'array', 'max:500'],
                'regions_focus' => ['required', 'array'],
                'org_type' => ['required', 'string', 'max:255'],
                'eng_prefer' => ['required', 'array'],
                //Required Fields

                // Misc
                'user_type_id' => ['required', 'integer', 'in:1,2,3,4,5'],
                'website' => ['nullable', 'url', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                //For adding role based users
                'role_id' => ['nullable', 'integer'], // optional, integer
                'capital_owner_id' => ['nullable', 'integer'],
            ]);

            $register = $regService->invCapitalRegister($data);
            return $register;
        }


        //Regular User (Type 4 & 5) Register
        $data = $request->validate([
            //Required Fields
            'fname' => ['required', 'string', 'max:255'],
            'lname' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'in:Male,Female,Other'],
            'dob' => ['required', 'date'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'user_type_id' => ['required', 'integer', 'in:1,2,3,4,5'],
            //'user_type_id' => ['required', 'integer', 'in:1,2,3,4,5'],
            //Required Fields
            'mname' => ['nullable', 'string', 'max:255'],

            'supplier_type' => [
                'nullable',
                'string',
                Rule::in([
                    'material_goods', 'business_service', 'labor',
                ]),
            ]
        ]);

        $userExists = User::where('email', $data['email'])->first();
        if($userExists)
        {
            return response()->json([ 'message' => 'Email already exists.'], 400);
        }

        $user = User::create([
            'fname' => $data['fname'],
            'mname' => $request->mname,
            'lname' => $data['lname'],
            'user_type_id' => $request->user_type_id,
            'email' => $data['email'],
            'gender' => $request->gender,
            'dob' => $request->dob,
            'password' => bcrypt($data['password']),
            'supplier_type' => $data['supplier_type'],
        ]);

        $token = $user->createToken('main')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'auth' => Auth::check()
        ]);
    }


    public function logout(Request $request)
    {
        $user = $request->user();

        $user->currentAccessToken()->delete();

        return response('',204);
    }


    # D e v i c e   V e r i f i c a t i o n

    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out from all devices'],204);
    }

    // H E L P E R S


}
