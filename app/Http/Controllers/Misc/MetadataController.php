<?php

namespace App\Http\Controllers\Misc;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MetadataController extends Controller
{
    public function show($type)
{
    $data = config("metadata.$type");

    if (is_null($data)) {
        return response()->json([
            'message' => 'Metadata type not found.'
        ], 404);
    }

    return response()->json([
        'type' => $type,
        'data' => $data,
    ]);
}

    public function index()
    {
        return response()->json([
            'data' => config('metadata')
        ]);
    }
}
