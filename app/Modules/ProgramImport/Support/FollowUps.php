<?php

namespace App\Modules\ProgramImport\Support;

use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\ProgramMilestone;
use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Models\ImportRecord;
use App\Modules\ProgramImport\Models\ImportUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an import left for a person to finish by hand, worked out from the data (so an item disappears by itself once it is fixed).
 * Shown in the Pending drawer through one thin scenario class. Everything is written in plain language and links to the exact import.
 *
 * @return list<array{type: string, id: string, title: string, subtitle: string, link: string, due: ?string}>
 */
class FollowUps
{
    public const RECENT_DAYS = 45;

    private const IMPORT_PAGE = 'dashboard.programOrg.programsBulkUpload';

    public static function forOrganization(int $organizationId): array
    {
        if (! config('program_imports.enabled') || ! Schema::hasTable('program_import_batches')) {
            return [];
        }
        $items = [];
        $batches = ImportBatch::where('organization_id', $organizationId)->where('updated_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->whereNotIn('status', ['cancelled', 'rolled_back'])->latest('id')->get();

        foreach ($batches as $b) {
            $link = self::IMPORT_PAGE.'?batch='.$b->id;
            $name = $b->original_filename ?: 'import #'.$b->id;
            $handled = (bool) ($b->stats['follow_up_handled'] ?? false);
            $commit = $b->stats['commit'] ?? [];

            if ($b->status === 'failed' && ! $handled) {
                $items[] = self::item('import.failed', $b, 'Your import did not finish', ($b->error_summary['message'] ?? 'Nothing was saved.').' Open it to try again or fix the file.', $link);
            }
            if ($b->status === 'importing' && $b->updated_at->lt(now()->subMinutes(20))) {
                $items[] = self::item('import.stuck', $b, 'An import is taking too long', "\"$name\" has been running for a while. Open it and press Try again; if it keeps stalling, ask your administrator to check the import queue.", $link);
            }
            if (in_array($b->status, ['completed', 'completed_with_errors'], true)) {
                $failed = (int) ($commit['failed'] ?? 0);
                if ($failed > 0 && ! $handled) {
                    $items[] = self::item('import.failed_rows', $b, 'Some rows from your import were not saved', "$failed row(s) in \"$name\" could not be saved. Open it to see why, download them, fix them and import them again.", $link);
                }
            }
        }

        $completed = $batches->whereIn('status', ['completed', 'completed_with_errors']);
        $ids = $completed->pluck('id')->all();
        if (! $ids) {
            return $items;
        }
        $latest = $completed->first();
        $link = self::IMPORT_PAGE.'?batch='.$latest->id;

        // Imported applicants nobody has invited yet: they exist but cannot log in
        $uninvited = ImportUser::whereIn('batch_id', $ids)->where('kind', 'shadow')->whereNull('claimed_at')
            ->whereIn('user_id', DB::table('users')->whereNull('password')->whereNull('invited_at')->select('id'))->count();
        if ($uninvited > 0) {
            $items[] = self::item('import.invite', $latest, 'Invite your imported applicants', "$uninvited applicant(s) were added from your import but cannot log in yet. Send them an invitation when you are ready.", $link);
        }

        // Imported awardees who still need a payment wallet (programs that pay applicants directly)
        $appIds = ImportRecord::whereIn('batch_id', $ids)->where('record_type', ProgramApplication::class)->where('action', 'created')->pluck('record_id');
        if ($appIds->isNotEmpty()) {
            $noWallet = DB::table('program_applications as a')->join('programs as p', 'p.id', '=', 'a.program_id')->join('users as u', 'u.id', '=', 'a.user_id')
                ->whereIn('a.id', $appIds)->where('a.status', 'awarded')->whereIn('p.disbursement_type', ['beneficiary', 'hybrid'])
                ->where(fn ($q) => $q->whereNull('u.lipr_wallet')->orWhere('u.lipr_wallet', ''))->count();
            if ($noWallet > 0) {
                $items[] = self::item('import.wallet', $latest, 'Awardees need a wallet before they can be paid', "$noWallet awardee(s) from your import have no payment wallet yet. They must claim their account and add one first.", $link);
            }

            // Active milestones of imported awardees with no supplier, in programs that pay suppliers
            $noSupplier = ProgramMilestone::whereIn('app_id', $appIds)->where('is_template', false)->whereDoesntHave('suppliers')
                ->whereIn('app_id', DB::table('program_applications as a')->join('programs as p', 'p.id', '=', 'a.program_id')->whereIn('p.disbursement_type', ['supplier', 'hybrid'])->select('a.id'))
                ->where('fund_release_status', '!=', 'released')->count();
            if ($noSupplier > 0) {
                $items[] = self::item('import.supplier', $latest, 'Milestones need a supplier', "$noSupplier milestone(s) from your import have no supplier yet, so funds cannot be released for them. Add a supplier to each milestone.", $link);
            }
        }

        return $items;
    }

    private static function item(string $type, ImportBatch $b, string $title, string $subtitle, string $link): array
    {
        return ['type' => $type, 'id' => (string) $b->id, 'title' => $title, 'subtitle' => $subtitle, 'link' => $link, 'due' => null];
    }
}
