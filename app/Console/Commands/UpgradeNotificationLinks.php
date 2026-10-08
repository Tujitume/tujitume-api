<?php

namespace App\Console\Commands;

use App\Models\Communication\Notifications;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Service\Notification\ProgramNotificationService;
use Illuminate\Console\Command;

/**
 * Notifications created before links carried exact ids point at list pages
 * (or at keys the app no longer understands). Their text still names the program,
 * round or business, so for program owners we can find the real target again.
 *
 * A row is only rewritten when the text matches exactly ONE program / round / application
 * of that owner; anything ambiguous is left alone.
 *
 *   php artisan notifications:upgrade-links            # dry run, prints what would change
 *   php artisan notifications:upgrade-links --apply    # writes the new links
 */
class UpgradeNotificationLinks extends Command
{
    protected $signature = 'notifications:upgrade-links {--apply : Write the new links (default is a dry run)}';

    protected $description = 'Rewrite old program-owner notification links so they open the exact page (dry run unless --apply).';

    private ProgramNotificationService $links;
    private int $changed = 0;
    private int $skipped = 0;

    public function handle(): int
    {
        $this->links = new ProgramNotificationService();
        $apply = (bool) $this->option('apply');

        Notifications::whereIn('type', ['program', 'program_fund_request'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($apply) {
                foreach ($rows as $notification) {
                    $new = $this->resolve($notification);

                    if (!$new || $new === $notification->link) {
                        $this->skipped++;
                        continue;
                    }

                    $this->line("#{$notification->id}  {$notification->link}  ->  {$new}");
                    if ($apply) $notification->update(['link' => $new]);
                    $this->changed++;
                }
            });

        // Booking notifications once pointed at a route that does not exist (dashboard.entrepreneur.mybookings::<id>)
        Notifications::where('link', 'like', 'dashboard.entrepreneur.mybookings%')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($apply) {
                foreach ($rows as $notification) {
                    $new = match ((int) \App\Models\Auth\User::whereKey($notification->receiver_id)->value('user_type_id')) {
                        4 => 'dashboard.programOrg.bookings',
                        5 => 'dashboard.capitalOrg.bookings',
                        default => 'dashboard.investor.bookings',
                    };

                    $this->line("#{$notification->id}  {$notification->link}  ->  {$new}");
                    if ($apply) $notification->update(['link' => $new]);
                    $this->changed++;
                }
            });

        $verb = $apply ? 'Updated' : 'Would update';
        $this->info("{$verb} {$this->changed} notification(s); left {$this->skipped} unchanged.");
        if (!$apply && $this->changed > 0) $this->comment('Re-run with --apply to save these links.');

        return self::SUCCESS;
    }

    /** The exact link for this notification, or null when it cannot be told apart safely. */
    private function resolve(Notifications $n): ?string
    {
        $owner = (int) $n->receiver_id;
        $text  = trim((string) $n->text);

        // All rounds done → the awardee's funding setup (the list when there are several)
        if (preg_match('/^All rounds completed for (.+)\. \d+ awardees? selected\./u', $text, $m)) {
            $program = $this->program($owner, $m[1]);
            return $program ? $this->links->orgAwardedLink(['program_id' => $program->id]) : null;
        }

        // Reviewer finished scoring → that round's applications tab, to review the scores
        if (preg_match('/^.+ has completed scoring for (.+)\. Please review and finalize\.$/u', $text, $m)) {
            return $this->roundLink($this->round($owner, $m[1]), ['tab' => 'applications', 'hash' => 'round-applications']);
        }

        // Every application scored → that round with the "finalize round" dialog open
        // (unless the round has already been finalized)
        if (preg_match('/^All applications for (.+) have been scored\./u', $text, $m)) {
            $round = $this->round($owner, $m[1]);
            return $this->roundLink($round, ['finalize' => $round && $round->status !== 'finalized']);
        }

        // Reviewer accepted but the wallet cannot cover the fee → the deposit dialog
        if (preg_match('/^A reviewer accepted the assignment for (.+?) in (.+)\. Deposit .+ to the program wallet/u', $text, $m)) {
            $program = $this->program($owner, $m[2]);
            return $program ? $this->links->orgDepositLink(['program_id' => $program->id]) : null;
        }

        // Wallet balance is low → the deposit dialog
        if (preg_match('/^(.+) wallet balance is low:/u', $text, $m)) {
            $program = $this->program($owner, $m[1]);
            return $program ? $this->links->orgDepositLink(['program_id' => $program->id]) : null;
        }

        // Money arrived in the wallet → the program page, where the balance is shown
        if (preg_match('/^.+ deposited to (.+) wallet$/u', $text, $m)) {
            $program = $this->program($owner, $m[1]);
            return $program ? $this->links->orgProgramLink(['program_id' => $program->id, 'hash' => 'program-wallet']) : null;
        }

        // Reviewer delivered work / its payment failed → the matching Orders tab
        if (preg_match('/^.+ has submitted their .+ work for .+\.$/u', $text)) {
            return $this->links->orgOrdersLink('delivered');
        }
        if (preg_match('/^Payment to reviewer .+ failed for .+\. Please retry\.$/u', $text)) {
            return $this->links->orgOrdersLink('completed');
        }

        // Reviewer accepted / declined an assignment → that round's applicants
        if (preg_match('/^.+ (?:accepted|declined) the review assignment for (.+?) in (.+)\.$/u', $text, $m)) {
            $program = $this->program($owner, $m[2]);
            return $program
                ? $this->roundLink($this->round($owner, $m[1], $program->id), ['tab' => 'reviewers', 'hash' => 'round-reviewers'])
                : null;
        }

        // Program lifecycle → the program's manage page
        if (preg_match('/^(.+) (?:has been published|is now accepting applications|is no longer accepting applications)$/u', $text, $m)
            || preg_match('/^(.+) has been finalized\./u', $text, $m)) {
            $program = $this->program($owner, $m[1]);
            return $program ? $this->links->orgProgramLink(['program_id' => $program->id]) : null;
        }

        // Business submitted MPRV / completion → that deal room tab
        if (preg_match('/^(.+) submitted MPRV for Milestone (\d+)$/u', $text, $m)) {
            $app = $this->application($owner, $m[1]);
            return $app ? $this->links->orgDealroomLink($app->id, 'mprv', 'milestone-' . $m[2]) : null;
        }
        if (preg_match('/^(.+) submitted completion for Milestone (\d+)$/u', $text, $m)) {
            $app = $this->application($owner, $m[1]);
            return $app ? $this->links->orgDealroomLink($app->id, 'final-approval', 'milestone-' . $m[2]) : null;
        }

        // New application / funding request → that applicant (customer_id is the applicant's user id)
        if ($text === 'You have a new application pitch.' || $n->type === 'program_fund_request') {
            $apps = ProgramApplication::where('program_owner_id', $owner)
                ->where('user_id', $n->customer_id)
                ->limit(2)->get();
            if ($apps->count() !== 1) return null;

            $app = $apps->first();
            return $this->links->orgApplicantLink([
                'program_id'     => $app->program_id,
                'application_id' => $app->id,
                'round_id'       => $app->program?->grant_type === 'multi_round' ? $app->current_round_id : null,
            ]);
        }

        return null;
    }

    private function roundLink(?ProgramRound $round, array $extra = []): ?string
    {
        return $round
            ? $this->links->orgRoundLink(['program_id' => $round->program_id, 'round_id' => $round->id] + $extra)
            : null;
    }

    // Each lookup answers only when it finds exactly one match among the owner's records.

    private function program(int $owner, string $title): ?Program
    {
        $found = Program::where('user_id', $owner)->where('program_title', $title)->limit(2)->get();
        return $found->count() === 1 ? $found->first() : null;
    }

    private function round(int $owner, string $name, ?int $programId = null): ?ProgramRound
    {
        $found = ProgramRound::whereIn('program_id', Program::where('user_id', $owner)->select('id'))
            ->where('round_name', $name)
            ->when($programId, fn ($query) => $query->where('program_id', $programId))
            ->limit(2)->get();
        return $found->count() === 1 ? $found->first() : null;
    }

    private function application(int $owner, string $startupName): ?ProgramApplication
    {
        $found = ProgramApplication::where('program_owner_id', $owner)
            ->where('startup_name', $startupName)
            ->limit(2)->get();
        return $found->count() === 1 ? $found->first() : null;
    }
}
