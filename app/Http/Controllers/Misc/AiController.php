<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Models\Business\Listing;
use App\Models\Capital\CapitalOffer;
use App\Models\Programs\Program;
use App\Models\Services\Services;
use App\Service\AI\CapitalMarketAnalysis;
use App\Service\AI\SuggestionsForACapital;
use App\Service\AI\SuggestionsForAProgram;
use App\Service\AI\SuggestionsForAListing;
use App\Service\AI\SuggestionsForAService;
use App\Service\AiScore\ListingScore;
use App\Service\AiScore\ListingToServiceScore;
use App\Service\AiScore\ServiceScore;
use App\Service\Misc\ErrorLogService;
use Illuminate\Support\Facades\Auth;

class AiController extends Controller
{
    public function index()
    {
        try{
            $scores =array();
            // Sample Data
            //$testData = new TestData();
            //$investor = $testData->investorData();
            //$listings = $testData->listingData();
            // Sample Data

            $investor = Auth::user();
            $listings = Listing::withCount('liked')->get();
            $listingScore = new ListingScore($investor);

            foreach ($listings as $listing) {
                $listing->liked = $listing->liked()->where('user_id', $investor->id)->exists();
                $score = $listingScore->calculate($listing);
                $scores[] = [
                    'score'   => $score['total'],
                    'listing' => $listing,
                    'breakdown'   => $score['breakdown'],
                ];
                rsort($scores);
            }
            return response()->json(['scores' => $scores]);
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


    public function index_s()
    {
        try{

            $scores =array();
            // Sample Data
            //$testData = new TestData();
            //$investor = $testData->investorData();
            //$listings = $testData->listingData();
            // Sample Data

            $investor = Auth::user();
            $listings = Services::withCount('liked')->get();

            $listingScore = new ServiceScore($investor);

            foreach ($listings as $listing) {
                $listing->liked = $listing->liked()->where('user_id', $investor->id)->exists();
                $score = $listingScore->calculate($listing);
                $scores[] = [
                    'score'   => $score['total'],
                    'service' => $listing,
                    'breakdown'   => $score['breakdown'],
                ];
                rsort($scores);
            }
            return response()->json(['scores' => $scores]);
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


    public function index_ls($listing_id)
    {
        try{
            // Sample Data
            //$testData = new TestData();
            //$listings = $testData->listingData();

            $scores =array();
            $user_id = Auth::id();
            $serviceScore = new ListingToServiceScore();

            $listing = Listing::where('id', $listing_id)->first();
            $services = Services::withCount('liked')->latest()->get();

            $pivotData = $serviceScore->preparePivotData($listing->toArray());
            foreach ($services as $service) {
                $service->liked = $service->liked()->where('user_id', $user_id)->exists();

                $score = $serviceScore->calculate($service, $pivotData);
                $scores[] = [
                    'score'   => round($score['total'],2),
                    'service' => $service,
                    'breakdown'   => $score['breakdown'],
                    'pivotData'   => $pivotData,
                ];
                rsort($scores);
            }
            return response()->json(['scores' => $scores]);
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


    public function capital_market_analysis()
    {
        try{
            $capitalAnalysis = new CapitalMarketAnalysis();
            $data = $capitalAnalysis->trendingData();
            return response()->json(['trending' => $data]);
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

    // S u g g e s t i o n s

    public function listing_suggestions($listing_id)
    {
        try{
            $investor = Auth::user();
            $pivot_listing = Listing::find($listing_id);
            $suggested = SuggestionsForAListing::for($pivot_listing)->limit(10)->get();
            return response()->json(['listings' => $suggested]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function service_suggestions($listing_id)
    {
        try{
            $investor = Auth::user();
            $pivot_listing = Services::find($listing_id);
            $suggested = SuggestionsForAService::for($pivot_listing)->limit(10)->get();
            return response()->json(['listings' => $suggested]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function program_suggestions($program_id)
    {
        try{
            $investor = Auth::user();
            $pivot_listing = Program::find($program_id);
            $suggested = SuggestionsForAProgram::for($pivot_listing)->limit(10)->get();
            return response()->json(['programs' => $suggested]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function capital_suggestions($capital_id)
    {
        try{
            $investor = Auth::user();
            $pivot_listing = CapitalOffer::find($capital_id);
            $suggested = SuggestionsForACapital::for($pivot_listing)->limit(10)->get();
            return response()->json(['capitals' => $suggested]);
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, ['input' => request()->except(['password', 'token']),]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


}
