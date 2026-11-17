<?php

namespace App\Modules\ProgramImport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponseResource;
use App\Models\Programs\Program;
use App\Modules\ProgramImport\Models\ImportLink;
use App\Modules\ProgramImport\Policies\ImportAccess;
use App\Modules\ProgramImport\Support\InvitationService;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function store(Request $r, int $program)
    {
        abort_unless(ImportAccess::canWrite($r->user()), 403);
        $orgId = ImportAccess::organizationId($r->user());
        $p = Program::where('organization_id', $orgId)->findOrFail($program);
        $in = $r->validate(['user_ids' => ['nullable', 'array', 'max:500'], 'user_ids.*' => ['integer']]);
        if (! ImportLink::where('organization_id', $orgId)->where('entity_type', 'program')->where('entity_id', $p->id)->exists()
            && ! ImportLink::where('organization_id', $orgId)->where('entity_type', 'application')->where('external_ref', 'like', $p->id.':%')->exists()) {
            return ApiResponseResource::error('This program has no imported applicants.', ['code' => 'not_imported'], 422);
        }

        return ApiResponseResource::success('Invitations sent.', (new InvitationService)->send($p, $in['user_ids'] ?? null));
    }
}
