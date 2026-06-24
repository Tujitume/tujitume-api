<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Models\Business\BusinessDocs;
use App\Models\Business\Listing;
use App\Models\Services\Services;
use App\Service\Misc\ErrorLogService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LookupController extends Controller
{
    /* all filtering methods */
    public function listingsByCategory(string $name)
    {
        try {
            $userId = Auth::id();

            // Bug fix: was == (comparison) not = (assignment)
            if ($name === 'Project-Management') $name = '0';

            $name = str_replace(['-', '_'], ['/', ' '], $name);

            $listings = Listing::withCount('liked')
                ->where('active', 1)
                ->where('category', $name)
                ->get()
                ->each(function ($list) use ($userId) {
                    $media      = businessDocs::where('business_id', $list->id)->where('media', 1)->first();
                    $list->file = $media->file ?? false;
                    $list->investment_needed = number_format($list->investment_needed);
                    $list->liked = $userId ? $list->liked()->where('user_id', $userId)->exists() : false;
                });

            $services = Services::withCount('liked')
                ->where('category', $name)
                ->get()
                ->each(function ($service) use ($userId) {
                    $service->liked = $userId ? $service->liked()->where('user_id', $userId)->exists() : false;
                });

            return response()->json(['data' => $listings, 'services' => $services], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['name' => $name]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }


    public function listingCountByCategory()
    {
        try {
            $grouped = Listing::groupBy('category')
                ->select('category', DB::raw('count(*) as total'))
                ->get();

            return response()->json([
                'data'          => $grouped,
                'listing_count' => Listing::count(),
            ], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token'])]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function filterListingsByTurnover(int $min, int $max, string $ids)
    {
        try {
            $results = [];
            foreach (explode(',', $ids) as $id) {
                if ($id === '') continue;

                $listing = Listing::find($id);
                if (!$listing) continue;

                $range  = explode('-', $listing->y_turnover);
                $dbMax  = (int) ($range[1] ?? $range[0] ?? 0);

                if ($min > $dbMax || $max < $dbMax) continue;

                $media         = businessDocs::where('business_id', $id)->where('media', 1)->first();
                $listing->file = $media->file ?? false;
                $listing->lat  = (float) $listing->lat;
                $listing->lng  = (float) $listing->lng;
                $listing->investment_needed = number_format($listing->investment_needed);
                $listing->y_turnover = number_format($range[0]) . '-' . number_format($range[1] ?? $range[0]);

                $results[] = $listing;
            }

            return response()->json(['data' => $results], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['min' => $min, 'max' => $max, 'ids' => $ids]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }


    public function filterListingsByAmount(int $min, int $max, string $ids)
    {
        try {
            $results = [];

            foreach (explode(',', $ids) as $id) {
                if ($id === '') continue;

                $listing = Listing::find($id);
                if (!$listing) continue;

                $needed = (int) $listing->investment_needed;
                if ($min > $needed || $max < $needed) continue;

                $media         = businessDocs::where('business_id', $id)->where('media', 1)->first();
                $listing->file = $media->file ?? false;
                $listing->lat  = (float) $listing->lat;
                $listing->lng  = (float) $listing->lng;

                $listing->investment_needed = number_format($listing->investment_needed);

                $turnover = explode('-', $listing->y_turnover);
                $listing->y_turnover = number_format($turnover[0]) . '-' . number_format($turnover[1] ?? $turnover[0]);

                $results[] = $listing;
            }

            return response()->json(['data' => $results], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['min' => $min, 'max' => $max, 'ids' => $ids]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function filterServicesByPrice(int $min, int $max, string $ids)
    {
        try {
            $results = [];

            foreach (explode(',', $ids) as $id) {
                if ($id === '') continue;

                $listing = Services::find($id);
                if (!$listing) continue;

                $price = (int) $listing->price;
                if ($min > $price || $max < $price) continue;

                $listing->price = number_format($listing->price);
                $listing->lat   = (float) $listing->lat;
                $listing->lng   = (float) $listing->lng;

                $results[] = $listing;
            }

            return response()->json(['data' => $results], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['min' => $min, 'max' => $max, 'ids' => $ids]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }
}
