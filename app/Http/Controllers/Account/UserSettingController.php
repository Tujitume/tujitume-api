<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Auth\UserSetting;
use App\Service\File\ImageUploadService;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class UserSettingController extends Controller
{
    // GET /user/settings
    public function show()
    {
        $settings = UserSetting::firstOrCreate(
            ['user_id' => Auth::id()],
            UserSetting::defaults()
        );

        return response()->json(['data' => $settings->toFrontendArray()], 200);
    }

    // GET /user/settings/logo
    public function logo()
    {
        try {
            $settings = UserSetting::firstOrCreate(
                ['user_id' => Auth::id()],
                UserSetting::defaults()
            );

            if (!$settings->logo) {
                return response()->json(['message' => 'No logo uploaded.'], 404);
            }

            if (str_starts_with($settings->logo, 'data:image/')) {
                [$meta, $payload] = explode(',', $settings->logo, 2);
                $mime = str_replace(['data:', ';base64'], '', $meta);

                return response(base64_decode($payload), 200)
                    ->header('Content-Type', $mime)
                    ->header('Cache-Control', 'private, max-age=300');
            }

            $storageBaseUrl = rtrim((string) config('filesystems.disks.s3.url'), '/');

            if (!$storageBaseUrl || !str_starts_with($settings->logo, $storageBaseUrl . '/')) {
                return response()->json(['message' => 'Logo source is not supported.'], 422);
            }

            $logo = Http::timeout(10)->get($settings->logo);

            if (!$logo->successful()) {
                return response()->json(['message' => 'Unable to load logo.'], 404);
            }

            return response($logo->body(), 200)
                ->header('Content-Type', $logo->header('Content-Type', 'image/png'))
                ->header('Cache-Control', 'private, max-age=300');
        } catch (\Exception $e) {
            ErrorLogService::report($e, ['user_id' => Auth::id()]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    // PATCH /user/settings
    public function update(Request $request, ImageUploadService $imageUpload)
    {
        try {
            $validated = $request->validate([
                'theme'               => 'sometimes|in:' . implode(',', UserSetting::THEME_KEYS),
                'mode'                => 'sometimes|in:' . implode(',', UserSetting::MODE_KEYS),
                'accent_color'        => 'sometimes|string|max:20',
                'logo'                => 'sometimes|nullable|string|max:300',
                'logo_file'           => 'sometimes|nullable|image|max:5120',
                'bg_color'            => 'sometimes|nullable|string|max:20',
                'font_weight'         => 'sometimes|in:light,semi-bold,bold',
                'subscription_status' => 'sometimes|in:active,inactive',
                'language'            => 'sometimes|string|max:10',
                'currency'            => 'sometimes|string|max:10',
                'timezone'            => 'sometimes|string|max:60',
                'date_format'         => 'sometimes|string|max:20',
                'supported_currencies'=> 'sometimes|nullable|array',
                'supported_languages' => 'sometimes|nullable|array',
                'profile_visibility'  => 'sometimes|in:public,private',
                'email_notifications' => 'sometimes|boolean',
                'push_notifications'  => 'sometimes|boolean',
                'custom'              => 'sometimes|nullable|array',
            ]);

            unset($validated['logo_file']);

            if ($request->hasFile('logo_file')) {
                $validated['logo'] = $imageUpload->save($request->file('logo_file'), 'images/settings/logos');
            }

            $settings = UserSetting::updateOrCreate(
                ['user_id' => Auth::id()],
                $validated
            );

            return response()->json([
                'message' => 'Settings updated successfully',
                'data'    => $settings->toFrontendArray(),
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
            $settings = UserSetting::updateOrCreate(
                ['user_id' => Auth::id()],
                UserSetting::defaults()
            );

            return response()->json([
                'message' => 'Settings reset to defaults',
                'data' => $settings->toFrontendArray(),
            ], 200);
        } catch (\Exception $e) {
            ErrorLogService::report($e, []);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }
}
