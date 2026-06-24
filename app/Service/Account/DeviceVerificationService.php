<?php
namespace App\Service\Account;
use App\Models\Auth\DeviceVerification;
use App\Models\Auth\User;
use App\Models\Auth\UserDevice;

use App\Notifications\NewDeviceVerificationCode;
use App\Support\DeviceFingerprint;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceVerificationService
{
    protected User $user;
    public function createVerification($user, $request)
    {

        // Use header X-Device-UUID or generate a new one
        //$uuid = DeviceFingerprint::getOrCreateUuid($request);
        $uuid = $request->header('X-Device-UUID') ?? (string) Str::uuid();
        $ua = DeviceFingerprint::parseUA($request);

        $device = UserDevice::firstOrCreate(
            ['user_id' => $user->id, 'device_uuid' => $uuid],
            [
                'name'    => $ua['platform'] ?? 'Unknown',
                'platform'       => $ua['platform'],
                'browser'        => $ua['browser'],
                'ip'     => $request->ip(),
                'last_active_at' => now(),
                'is_verified'    => false,
            ]
        );

        if (! $device->is_verified) {
            $code = random_int(100000, 999999);

            \App\Models\Auth\DeviceVerification::updateOrCreate(
                ['user_id' => $user->id, 'user_device_id' => $uuid],
                [
                    'code' => $code,
                    'expires_at' => Carbon::now()->addMinutes(10),
                    'attempts'   => 0,
                ]
            );
            $deviceLabel = $device->name ?? ($device->platform . ' - ' . $device->browser);
            $user->notify(new NewDeviceVerificationCode($code,$deviceLabel));

            return response()->json([
                'status' => 'verification_required',
                'message' => 'Verification code sent',
                'device_uuid' => $uuid,
            ], 202);
        }

        // O p t i o n a l   Single device
        if (config('auth_extras.single_device_enforcement')) {
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->where('id','<>',$request->session()->getId())
                ->delete();

            \App\Models\Auth\UserSession::where('user_id', $user->id)
                ->where('id','<>',$request->session()->getId())
                ->delete();
        }
    }

    public function verify(Request $request)
    {
        $request->validate([
            'device_uuid' => ['required'],
            'code'        => ['required', 'digits:6'],
        ]);

        $verification = DeviceVerification::where('user_device_id', $request->device_uuid)
            ->where('code', $request->code)
            ->where('expires_at', '>', now())
            ->first();

        if (! $verification) {
            return response()->json(['status' => 'failed', 'message' => 'Invalid or expired code'], 422);
        }

        //  Expired?
        if ($verification->expires_at->isPast()) {
            $verification->delete();
            return response()->json(['status' => 'failed', 'message' => 'Code expired'], 422);
        }

        //  Attempt cap
        if ($verification->attempts >= 5) {
            return response()->json(['status' => 'failed', 'message' => 'Too many attempts. Try again later.'], 429);
        }

        $device = UserDevice::where('device_uuid', $request->device_uuid)->firstOrFail();
        $device->is_verified = true;
        $device->save();

        $verification->delete();

        $user = $device->user;

        // Generate Sanctum token after successful verification
        $token = $user->createToken('main')->plainTextToken;

        return response()->json([
            'status' => 'verified',
            'token' => $token,
        ]);
    }

}
