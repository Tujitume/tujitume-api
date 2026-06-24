<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use App\Models\Business\BusinessBids;
use App\Models\Business\Listing;
use App\Models\Milestones\Milestones;
use App\Models\Services\Smilestones;
use App\Service\Misc\ErrorLogService;
use Illuminate\Http\Request;

class DownloadController extends Controller
{
    public function downloadMilestoneDoc(int $milestoneId)
    {
        try {
            $milestone = Milestones::findOrFail($milestoneId);

            if (!$milestone->document || !file_exists(public_path($milestone->document))) {
                return response()->json(['message' => 'File not found.'], 404);
            }

            return response()->download(public_path($milestone->document));

        } catch (Exception $e) {
            ErrorLogService::report($e, ['milestone_id' => $milestoneId]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }


    public function downloadBidsDoc(string $doc)
    {
        try {
            $doc = base64_decode($doc);

            // Strip base URL if present
            if (str_contains($doc, env('API_BASE_URL'))) {
                $doc = explode(env('API_BASE_URL'), $doc)[1];
            }

            if (!$doc || !file_exists(public_path($doc))) {
                return response()->json(['message' => 'File not found.'], 404);
            }

            return response()->download(public_path($doc));

        } catch (Exception $e) {
            ErrorLogService::report($e, ['doc' => $doc ?? null]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    public function download_business($id){
        $doc = Listing::where('id',$id)->first();
        $file=$doc->document;
        if( $file == null || !file_exists(public_path($file)) ){
            return response('404');
        }

        $headers = array('Content-Type'=> 'application/pdf');
        $url= public_path($file);
        $extension = pathinfo($url, PATHINFO_EXTENSION);

        response()->json(['type'=>$extension]);
        return response()->download($url);
        //return response()->json(['data'=>'success']);

    }

    public function download_statement($id){
        $doc = Listing::where('id',$id)->first();
        $file=$doc->yeary_fin_statement;
        if( $file == null || !file_exists(public_path($file)) ){

            return response('404');
        }


        else{
            $headers = array('Content-Type'=> 'application/pdf');
            $url= public_path($file);
            $extension = pathinfo($url, PATHINFO_EXTENSION);

            response()->json(['type'=>$extension]);
            return response()->download($url);
        }
        //return Response::download($file, 'business_statement.pdf', $headers);

    }

    public function assetDownload(string $id, string $type)
    {
        try {
            if ($type === 'photos') {
                $path = str_replace('__', '/', $id);
                if (!$path || !file_exists($path)) {
                    return response()->json(['message' => 'File not found.'], 404);
                }
                return Response::download($path);
            }

            $bid = BusinessBids::findOrFail($id);

            $document = match($type) {
                'legal_doc'    => $bid->legal_doc,
                'optional_doc' => $bid->optional_doc,
                default        => null,
            };

            if (!$document || !file_exists($document)) {
                return response()->json(['message' => 'File not found.'], 404);
            }
            return Response::download($document, $type . '.pdf', ['Content-Type' => 'application/pdf']);

        } catch (Exception $e) {
            ErrorLogService::report($e, ['id' => $id, 'type' => $type]);
            return response()->json(['message' => 'Something went wrong, please try again later.'], 500);
        }
    }

    //Service Milestone Doc Download
    public function downloadServiceMilestoneDoc($id, $mile_id){

        $doc = Smilestones::where('id',$mile_id)->first();
        if($doc)
            $file=$doc->document;

        if( !$file || !file_exists(public_path($file)) ){

            return response('404');
        }
        $headers = array('Content-Type'=> 'application/pdf');
        $url= public_path($file);
        $extension = pathinfo($url, PATHINFO_EXTENSION);

        response()->json(['type'=>$extension]);
        return response()->download($url);

    }

}
