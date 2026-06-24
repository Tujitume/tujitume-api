<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Models\Business\Listing;
use App\Models\Misc\Prospects;
use App\Models\Misc\Reports;
use App\Models\Services\Services;
use App\Service\Misc\ErrorLogService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupportController extends Controller
{
    public function newsletterSubscribe(string $email)
    {
        try {
            $validated = validator(['email' => $email], [
                'email' => 'required|email|max:255',
            ])->validate();

            Prospects::create(['email' => $validated['email']]);

            $this->emailService->send(
                'Subscribe to Jitume',
                'subscribe_mail',
                [],
                $validated['email'],
            );

            return response()->json([
                'status'  => 200,
                'message' => 'Thank you for subscribing! You will receive an email with updates.',
            ], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['email' => $email]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function reportListing(Request $request)
    {
        $uploadedFiles = [];

        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'listing_id' => 'required|integer',
                'type'       => 'required|in:1,2',
                'category'   => 'required|string|max:255',
                'details'    => 'required|string|max:2000',
                'document'   => 'nullable|file|mimes:pdf,docx|max:5120',
            ]);

            $listing = $validated['type'] == 1
                ? Listing::findOrFail($validated['listing_id'])
                : Services::findOrFail($validated['listing_id']);

            $user = Auth::user();

            $report = Reports::create([
                'user_id'      => $user->id,
                'listing_id'   => $validated['listing_id'],
                'listing_name' => $listing->name,
                'owner_id'     => $listing->user_id,
                'type'         => $validated['type'],
                'category'     => $validated['category'],
                'details'      => $validated['details'],
                'document'     => null,
            ]);

            if ($request->hasFile('document')) {
                $path            = $this->fileUpload->saveFile($request->file('document'), 'files/reports/' . $report->id);
                $uploadedFiles[] = $path;
                $report->update(['document' => $path]);
            }

            DB::commit();

            $this->emailService->send(
                'Report Submitted',
                'report_mail',
                ['listing_name' => $listing->name, 'category' => $validated['category'], 'id' => $report->id],
                $user->email,
            );

            return response()->json(['status' => 200, 'message' => 'Report submitted successfully.'], 200);

        } catch (Exception $e) {
            DB::rollBack();
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
