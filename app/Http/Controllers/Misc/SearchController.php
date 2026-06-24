<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Models\Business\Listing;
use App\Models\Services\Services;
use App\Service\Misc\ErrorLogService;
use App\Service\Misc\GetPlaces;
use App\Service\Misc\LocationService;
use Exception;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function locations(string $query, GetPlaces $places)
    {
        try {
            return response()->json($places->search($query));

        } catch (Exception $e) {
            ErrorLogService::report($e, ['query' => $query]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function airportSearch(string $search)
    {
        try {
            $airports = json_decode(file_get_contents(public_path('js/airports.json')), true);
            $term     = strtolower($search);

            $results = collect($airports)
                ->filter(fn($loc) =>
                    str_contains(strtolower($loc['name']), $term) ||
                    str_contains(strtolower($loc['city']), $term) ||
                    str_contains(strtolower($loc['country']), $term)
                )
                ->map(fn($loc) => [
                    'name'    => $loc['name'],
                    'city'    => $loc['city'],
                    'country' => $loc['country'],
                ])
                ->values();

            return response()->json(['data' => $results]);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['search' => $search]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    /* Search Listing */
    public function search(Request $request, LocationService $locationService)
    {
        try {
            $name     = $request->listing_name;
            $location = $request->location;
            $category = $request->category;
            $lat      = (float) $request->lat;
            $lng      = (float) $request->lng;
            $hasLoc   = $location !== '' && $location !== null;

            $listings = match (true) {
                !empty($name)              => Listing::where('active', 1)->where('name', 'like', "%{$name}%")->get(),
                $hasLoc && empty($category) => $locationService->getNearestListings($lat, $lng, 100),
                !$hasLoc && !empty($category) => Listing::where('active', 1)->where('category', $category)->get(),
                $hasLoc                    => $locationService->getNearestListings($lat, $lng, 100),
                default                    => Listing::where('active', 1)->get(),
            };

            return response()->json(['results' => $listings, 'loc' => $hasLoc, 'success' => 'Success'],200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

    public function searchService(Request $request, LocationService $locationService)
    {
        try {
            $name     = $request->listing_name;
            $location = $request->search;
            $category = $request->category;
            $lat      = (float) $request->lat;
            $lng      = (float) $request->lng;
            $hasLoc   = !empty($location);

            $listings = match (true) {
                $hasLoc          => $locationService->getNearestServices($lat, $lng, 100),
                !empty($name)    => Services::where('active', 1)->where('name', 'like', "%{$name}%")->get(),
                !empty($category) => Services::where('active', 1)->where('category', $category)->get(),
                default          => Services::get(),
            };

            return response()->json([
                'results' => $listings,
                'loc'     => $hasLoc,
                'success' => 'Success',
                'count'   => $listings->count(),
            ], 200);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['input' => $request->except(['password', 'token'])]);
            return response()->json([
                'message' => 'Something went wrong, please try again later.'
            ], 500);
        }
    }

}
