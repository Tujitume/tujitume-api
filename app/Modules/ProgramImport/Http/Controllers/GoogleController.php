<?php

namespace App\Modules\ProgramImport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponseResource;
use App\Modules\ProgramImport\Models\ImportToken;
use App\Modules\ProgramImport\Policies\ImportAccess;
use App\Modules\ProgramImport\Support\FormFetchException;
use App\Modules\ProgramImport\Support\GoogleConnection;
use Illuminate\Http\Request;

/** Connect a Google account (read-only scopes) so forms that need a sign-in can be read. Off unless configured. */
class GoogleController extends Controller
{
    public function status(Request $r)
    {
        abort_unless(ImportAccess::canRead($r->user()), 403);
        $org = ImportAccess::organizationId($r->user());

        return ApiResponseResource::success('Google connection', ['configured' => GoogleConnection::configured(), 'connected' => GoogleConnection::configured() && ImportToken::where('organization_id', $org)->where('provider', 'google')->exists()]);
    }

    public function connect(Request $r)
    {
        abort_unless(ImportAccess::canWrite($r->user()), 403);
        if (! GoogleConnection::configured()) {
            return $this->notConfigured();
        }

        return ApiResponseResource::success('Open this link to connect Google.', ['url' => (new GoogleConnection)->authUrl(ImportAccess::organizationId($r->user()), $r->user()->id)]);
    }

    /** Public route: Google redirects the browser here. Identity comes from the signed state, never from the query. */
    public function callback(Request $r)
    {
        if (! GoogleConnection::configured()) {
            return $this->notConfigured();
        }
        try {
            (new GoogleConnection)->complete((string) $r->query('state'));
        } catch (FormFetchException|\Throwable) {
            return ApiResponseResource::error('The Google connection could not be completed.', ['code' => 'google_state']);
        }

        return redirect(rtrim((string) config('app.app_url'), '/').'/?google_connected=1');
    }

    public function disconnect(Request $r)
    {
        abort_unless(ImportAccess::canWrite($r->user()), 403);
        if (! GoogleConnection::configured()) {
            return $this->notConfigured();
        }
        (new GoogleConnection)->disconnect(ImportAccess::organizationId($r->user()));

        return ApiResponseResource::success('Google disconnected.', ['connected' => false]);
    }

    private function notConfigured()
    {
        return ApiResponseResource::error('Connecting Google is not configured on this server.', ['code' => 'google_not_configured']);
    }
}
