<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Auth\UserSetting;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class UserSettingController extends Controller
{
    // GET /user/settings
    public function show()
    {
        $settings = UserSetting::firstOrCreate(
            ['user_id' => Auth::id()],
            [
                'theme'               => 'default',
                'mode'                => 'system',
                'accent_color'        => '#14532d',
                'email_notifications' => true,
                'push_notifications'  => true,
                'marketing_emails'    => false,
            ]
        );

        return response()->json(['data' => $settings], 200);
    }

    // PATCH /user/settings
    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
                'theme'               => 'sometimes|in:default,ocean,forest,sunset,minimal',
                'mode'                => 'sometimes|in:light,dark,system',
                'accent_color'        => 'sometimes|string|max:20',
                'bg_color'            => 'sometimes|nullable|string|max:20',
                'font_size'           => 'sometimes|in:small,medium,large',
                'language'            => 'sometimes|string|max:10',
                'currency'            => 'sometimes|string|max:10',
                'timezone'            => 'sometimes|string|max:10',
                'profile_visibility'  => 'sometimes|in:public,private',
                'email_notifications' => 'sometimes|boolean',
                'push_notifications'  => 'sometimes|boolean',
                'marketing_emails'    => 'sometimes|boolean',
                'custom'              => 'sometimes|nullable|array',
            ]);

            $settings = UserSetting::updateOrCreate(
                ['user_id' => Auth::id()],
                $validated
            );

            return response()->json([
                'message' => 'Settings updated successfully',
                'data'    => $settings,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // DELETE /user/settings/reset
    public function reset()
    {
        try {
            UserSetting::where('user_id', Auth::id())->update([
                'theme'               => 'default',
                'mode'                => 'system',
                'accent_color'        => '#14532d',
                'bg_color'            => null,
                'font_size'           => 'medium',
                'language'            => 'en',
                'email_notifications' => true,
                'push_notifications'  => true,
                'marketing_emails'    => false,
                'custom'              => null,
            ]);

            return response()->json(['message' => 'Settings reset to defaults'], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, []);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }
}
