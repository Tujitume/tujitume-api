<?php

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Service\Organization\WorkspaceDirectory;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    /**
     * Public: does this workspace exist, and how should its sign-in page look?
     * GET /api/v1/workspaces/{slug}  (the slug is the first part of mastercard.tujitume.com)
     */
    public function resolve(string $slug)
    {
        $workspace = WorkspaceDirectory::find($slug);

        if (! $workspace) {
            return response()->json(['exists' => false, 'message' => 'Workspace not found.'], 404);
        }

        return response()->json([
            'exists'    => true,
            'slug'      => $workspace->slug,
            'name'      => WorkspaceDirectory::displayName($workspace),
            // pending_verification still signs in (the owner has to get in to finish verification); only suspended is closed
            'available' => $workspace->workspace_status !== 'suspended',
            'status'    => $workspace->workspace_status,
            'branding'  => WorkspaceDirectory::branding($workspace),
        ], 200);
    }
}
