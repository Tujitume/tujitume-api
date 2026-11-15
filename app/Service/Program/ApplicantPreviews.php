<?php

namespace App\Service\Program;

use Illuminate\Support\Facades\DB;

/**
 * Adds a small, real applicant preview (latest 3 applicants: id, name, image) to each program
 * as `applicant_previews`, without loading every application model.
 * Used by the owner programs list and the applications overview.
 */
class ApplicantPreviews
{
    public static function attach($programs, int $limit = 3): void
    {
        $programIds = collect($programs)->pluck('id')->filter()->values();
        if ($programIds->isEmpty()) {
            return;
        }

        $ranked = DB::table('program_applications as pa')
            ->leftJoin('users as applicant_users', 'applicant_users.id', '=', 'pa.user_id')
            ->whereIn('pa.program_id', $programIds)
            ->select([
                'pa.id', 'pa.program_id', 'pa.startup_name', 'pa.business_id',
                'applicant_users.first_name', 'applicant_users.last_name', 'applicant_users.image',
            ])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY pa.program_id ORDER BY pa.created_at DESC, pa.id DESC) as preview_rank');

        $previews = DB::query()->fromSub($ranked, 'ranked_applications')
            ->where('preview_rank', '<=', $limit)
            ->get()
            ->groupBy('program_id');

        collect($programs)->each(function ($program) use ($previews): void {
            $program->applicant_previews = collect($previews->get($program->id, []))->map(function ($application): array {
                $accountName = trim(implode(' ', array_filter([
                    $application->first_name,
                    $application->last_name,
                ], fn ($value) => $value && $value !== 'null')));

                return [
                    'id' => $application->id,
                    'name' => ! $application->business_id && $accountName !== '' ? $accountName : $application->startup_name,
                    'image' => ! $application->business_id ? $application->image : null,
                ];
            })->values()->all();
        });
    }
}
