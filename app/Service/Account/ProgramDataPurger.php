<?php

namespace App\Service\Account;

use App\Models\Auth\User;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes programs with everything hanging off them: applications (as applicant or owner), their uploaded files,
 * round documents and responses, milestones, verifications, deal-room documents and disbursements.
 * Used when a person deletes their own account (UserController::destroy) and when a 7-day trial account ends
 * (TrialAccountService::purge).
 */
class ProgramDataPurger
{
    public function forUser(User $user): void
    {
        $userId = $user->id;
        $programIds = Program::where('user_id', $userId)->pluck('id');

        $applicationIds = ProgramApplication::where(function ($query) use ($userId, $programIds) {
            $query->where('user_id', $userId)
                ->orWhere('program_owner_id', $userId);

            if ($programIds->isNotEmpty()) {
                $query->orWhereIn('program_id', $programIds);
            }
        })->pluck('id');

        $this->deleteProgramApplications($applicationIds->all());
        Program::where('user_id', $userId)
            ->get()
            ->each(function (Program $program): void {
                $program->delete();
            });
    }

    public function deleteProgramApplications(array $applicationIds): void
    {
        if (empty($applicationIds)) {
            return;
        }

        $applications = ProgramApplication::whereIn('id', $applicationIds)->get();
        $milestoneIds = DB::table('program_milestones')
            ->whereIn('app_id', $applicationIds)
            ->pluck('id');

        $applications->each(function (ProgramApplication $application): void {
            $this->deleteS3File($application->pitch_deck_file);
            $this->deleteS3File($application->business_plan_file);
            $this->deleteLocalFile($application->pitch_video);
        });

        DB::table('round_required_documents')
            ->whereIn('application_id', $applicationIds)
            ->pluck('file_path')
            ->each(fn (?string $path) => $this->deleteS3File($path));

        DB::table('application_round_responses')
            ->whereIn('application_id', $applicationIds)
            ->pluck('file_path')
            ->each(fn (?string $path) => $this->deleteS3File($path));

        if ($milestoneIds->isNotEmpty()) {
            $this->deleteProgramMilestones($milestoneIds->all());
        }

        ProgramApplication::whereIn('id', $applicationIds)->delete();
    }

    public function deleteProgramMilestones(array $milestoneIds): void
    {
        DB::table('program_milestones')
            ->whereIn('id', $milestoneIds)
            ->pluck('document')
            ->each(fn (?string $path) => $this->deleteS3File($path));

        DB::table('milestone_verifications')
            ->whereIn('milestone_id', $milestoneIds)
            ->pluck('document')
            ->each(fn (?string $path) => $this->deleteS3File($path));

        DB::table('deal_room_documents')
            ->whereIn('milestone_id', $milestoneIds)
            ->pluck('file_path')
            ->each(fn (?string $path) => $this->deleteS3File($path));

        DB::table('disbursements')
            ->whereIn('milestone_id', $milestoneIds)
            ->pluck('receipt_file')
            ->each(fn (?string $path) => $this->deleteS3File($path));

        DB::table('milestone_suppliers')
            ->whereIn('milestone_id', $milestoneIds)
            ->get(['invoice_file', 'quotation_file'])
            ->each(function ($supplier): void {
                $this->deleteS3File($supplier->invoice_file);
                $this->deleteS3File($supplier->quotation_file);
            });

        DB::table('disbursements')->whereIn('milestone_id', $milestoneIds)->delete();
        DB::table('deal_room_documents')->whereIn('milestone_id', $milestoneIds)->delete();
        DB::table('milestone_verifications')->whereIn('milestone_id', $milestoneIds)->delete();
        DB::table('milestone_completion_submissions')->whereIn('milestone_id', $milestoneIds)->delete();
        DB::table('program_milestones')->whereIn('id', $milestoneIds)->delete();
    }

    /** Programs of an organization (made by any of its people) and everything under them. */
    public function forOrganization(int $organizationId, array $userIds = []): void
    {
        $programIds = Program::where('organization_id', $organizationId)
            ->when($userIds !== [], fn ($q) => $q->orWhereIn('user_id', $userIds))
            ->pluck('id');

        $applicationIds = ProgramApplication::where(function ($query) use ($programIds, $userIds) {
            $query->whereIn('program_id', $programIds->all() ?: [0]);
            if ($userIds !== []) {
                $query->orWhereIn('user_id', $userIds)->orWhereIn('program_owner_id', $userIds);
            }
        })->pluck('id');

        $this->deleteProgramApplications($applicationIds->all());
        Program::whereIn('id', $programIds->all() ?: [0])->get()->each(fn (Program $program) => $program->delete());
    }

    private function deleteLocalFile(?string $path): void
    {
        if (! $path) {
            return;
        }

        $filePath = public_path($path);

        if (is_file($filePath)) {
            unlink($filePath);
        }
    }

    private function deleteS3File(?string $path): void
    {
        if (! $path) {
            return;
        }

        $baseUrl = rtrim((string) config('filesystems.disks.s3.url'), '/');
        $key = str_starts_with($path, $baseUrl.'/')
            ? substr($path, strlen($baseUrl) + 1)
            : ltrim((string) parse_url($path, PHP_URL_PATH), '/');

        if ($key) {
            Storage::disk('s3')->delete($key);
        }
    }
}
