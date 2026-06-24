<?php
namespace App\Service\Business\Milestone;
use App\Models\Milestones\Milestones;
use App\Models\Services\Services;
use App\Service\Misc\ErrorLogService;

class MilestonePMCandidates
{

    public function get(Milestones $milestone)
    {
        try{
            $results = array();
            $listing = $milestone->listing;
            if(!$listing){
                return false;
            }

            $location = $listing->location;
            $lat = (float)$listing->lat; $lng = (float)$listing->lng;

            $services = $this->findNearestServices($lat,$lng,100);
            $manager_ids = $services->pluck('user_id')->unique()->values()->toArray();

            return $manager_ids;
        }
        catch (\Exception $e) {
            ErrorLogService::report($e, [
                'input' => request()->except(['password', 'token']),
            ]);
            return false;
        }

    }

    public function findNearestServices($latitude, $longitude, $radius = 100)
    {
        $listings = Services::selectRaw("* ,
                         ( 3956 * acos( cos( radians(?) ) *
                           cos( radians( lat ) )
                           * cos( radians( lng ) - radians(?)
                           ) + sin( radians(?) ) *
                           sin( radians( lat ) ) )
                         ) AS distance", [$latitude, $longitude, $latitude])
            ->where('category', '=', 'project_management')
            ->having("distance", "<", $radius)
            ->orderBy("distance",'asc')
            ->offset(0)
            ->limit(20)
            ->get();
        //sort by service ratings


        return $listings;
    }


}
