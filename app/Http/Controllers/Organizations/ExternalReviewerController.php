<?php

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use Illuminate\Http\Request;

class ExternalReviewerController extends Controller
{
    /**
     * Return the external reviewer directory for organizations assigning
     * reviewers to program rounds.
     */
    public function index(Request $request)
    {
        if ((int) $request->user()->user_type_id !== 4) {
            return response()->json([
                'message' => 'Only organization users can view external reviewers.',
            ], 403);
        }

        $reviewers = User::query()
            ->where('user_type_id', 6)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get([
                'id',
                'first_name',
                'last_name',
                'display_name',
                'email',
                'phone',
                'image',
                'country',
                'city',
                'website',
            ]);

        return response()->json([
            'data' => $reviewers,
        ]);
    }
}
