<?php

namespace App\Modules\ProgramImport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponseResource;
use App\Modules\ProgramImport\Models\ImportSetting;
use App\Modules\ProgramImport\Policies\ImportAccess;
use Illuminate\Http\Request;

/** Organization-level consent for sending real (never contact) sample values to the AI provider while mapping. Off by default. */
class SettingsController extends Controller
{
    public function show(Request $r)
    {
        abort_unless(ImportAccess::canRead($r->user()), 403);

        return ApiResponseResource::success('Import settings', $this->payload(ImportAccess::organizationId($r->user())));
    }

    public function update(Request $r)
    {
        abort_unless(ImportAccess::canWrite($r->user()), 403);
        $in = $r->validate(['ai_real_samples' => ['required', 'boolean'], 'confirm_ai_data_sharing' => ['nullable', 'boolean']]);
        if ($in['ai_real_samples'] && ! $r->boolean('confirm_ai_data_sharing')) {
            return ApiResponseResource::error('Confirm you agree to send sample values to the AI provider.', ['code' => 'consent_required']);
        }
        $org = ImportAccess::organizationId($r->user());
        ImportSetting::updateOrCreate(['organization_id' => $org], $in['ai_real_samples']
            ? ['ai_real_samples' => true, 'consented_by' => $r->user()->id, 'consented_at' => now()]
            : ['ai_real_samples' => false]);

        return ApiResponseResource::success('Saved.', $this->payload($org));
    }

    private function payload(int $org): array
    {
        $s = ImportSetting::where('organization_id', $org)->first();

        return ['ai_real_samples' => (bool) $s?->ai_real_samples, 'consented_at' => $s?->consented_at, 'applies_only_when' => 'the AI provider also passes the platform privacy gate for your organization'];
    }
}
