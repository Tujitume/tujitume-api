<?php

namespace App\Http\Controllers\Capital;

use App\Http\Controllers\Controller;
use App\Service\Capital\MyCapitalApplicationList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET /capital/my-applications?stage=all|approved — the capital offers the signed-in business owner applied to, as a
 * server list (page, per_page, search, filters, sort, export). Only their own (or their organization owner's) applications.
 */
class MyCapitalApplicationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $stage = $request->query('stage') === 'approved' ? 'approved' : 'all';

        return (new MyCapitalApplicationList(Auth::user(), $stage))->respond($request);
    }
}
