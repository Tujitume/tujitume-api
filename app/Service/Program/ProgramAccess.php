<?php

namespace App\Service\Program;

use App\Models\Auth\User;
use App\Models\Programs\MilestoneVerification;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramMilestone;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\RoundReviewer;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * One place that answers "may this person open this program record?".
 *
 * Emailed and in-app links open records by id, so anyone holding (or guessing) a link
 * reaches the endpoint. Each endpoint calls an authorize*() method here; when the
 * answer is no it throws, and the exception handler turns that into the standard
 * 403 {success:false, code:"forbidden", message} the app shows as "You don't have access".
 */
class ProgramAccess
{
    public const DENIED = "You don't have access to this. It may belong to a different account.";

    // ── Questions ────────────────────────────────────────────────────────────

    /** Roles whose holders work on the organization's programs (reviewers have their own endpoints). */
    private const PROGRAM_TEAM_ROLES = ['super_admin', 'super-admin', 'admin', 'editor', 'viewer'];

    /**
     * Is the signed-in user the program's owner, or a program-team member of the owner's organization?
     * Used by the owner-only endpoints so a Viewer can open the organization's data. What each role may
     * change is decided by ProgramMiddleware (Viewer: read only, Editor: no deleting), not here.
     */
    public static function owns(?Program $program): bool
    {
        $user = auth()->user();
        if (!$user || !$program) return false;
        if ((int) $user->id === (int) $program->user_id) return true;
        if ((int) $user->user_type_id !== 4 || !$user->organization_id) return false;

        $user->loadMissing('organizationRole.role');
        if (!in_array($user->organizationRole?->role?->name, self::PROGRAM_TEAM_ROLES, true)) return false;

        return (int) $user->organizationOwnerId() === (int) $program->user_id;
    }

    /** The program owner's organisation (owner, creator and every team member). */
    public function ownsProgram(?User $user, Program $program): bool
    {
        if (!$user) return false;
        if ((int) $user->id === (int) $program->user_id) return true;

        $owner = $program->owner;
        if ($owner && $user->organization_id && (int) $user->organization_id === (int) $owner->organization_id) {
            return true;
        }

        return (int) $user->organizationOwnerId() === (int) $program->user_id;
    }

    /** The business that applied (the applicant and their organisation's team). */
    public function isApplicant(?User $user, ProgramApplication $application): bool
    {
        if (!$user) return false;
        if ((int) $user->id === (int) $application->user_id) return true;

        $applicant = $application->user;
        return $applicant && $user->organization_id
            && (int) $user->organization_id === (int) $applicant->organization_id;
    }

    /** A reviewer assigned to any round of the application's program. */
    public function isReviewer(?User $user, ProgramApplication $application): bool
    {
        if (!$user) return false;
        if ($application->assigned_reviewer_id && (int) $application->assigned_reviewer_id === (int) $user->id) return true;

        return RoundReviewer::where('user_id', $user->id)
            ->whereIn('round_id', ProgramRound::where('program_id', $application->program_id)->select('id'))
            ->exists();
    }

    /** A PM auditor asked to audit one of the application's milestones. */
    public function isAuditor(?User $user, ProgramApplication $application): bool
    {
        if (!$user) return false;

        return MilestoneVerification::where('auditor_id', $user->id)
            ->whereHas('milestone', fn ($q) => $q->where('app_id', $application->id))
            ->exists();
    }

    public function canViewApplication(?User $user, ProgramApplication $application): bool
    {
        $program = $application->program;

        return $this->isApplicant($user, $application)
            || ($user && $application->program_owner_id && (int) $application->program_owner_id === (int) $user->id)
            || ($program && $this->ownsProgram($user, $program))
            || $this->isReviewer($user, $application)
            || $this->isAuditor($user, $application);
    }

    // ── Guards (throw → 403) ─────────────────────────────────────────────────

    public function authorizeProgramOwner(?User $user, Program $program): void
    {
        $this->allow($this->ownsProgram($user, $program));
    }

    public function authorizeApplication(?User $user, ProgramApplication $application): void
    {
        $this->allow($this->canViewApplication($user, $application));
    }

    /** Round data is for the program's own team and the reviewers assigned to its rounds. */
    public function authorizeRound(?User $user, ProgramRound $round): void
    {
        $program = $round->program;

        $this->allow(
            ($program && $this->ownsProgram($user, $program))
            || ($user && RoundReviewer::where('round_id', $round->id)->where('user_id', $user->id)->exists())
        );
    }

    /** A milestone's records (budget, disbursements, verifications) follow its application. */
    public function authorizeMilestone(?User $user, ProgramMilestone $milestone): void
    {
        $application = $milestone->application;

        $this->allow($application && $this->canViewApplication($user, $application));
    }

    /** Everything about a program's applicants: the program's own team and its reviewers. */
    public function authorizeProgramStaff(?User $user, Program $program): void
    {
        $this->allow(
            $this->ownsProgram($user, $program)
            || ($user && RoundReviewer::where('user_id', $user->id)
                ->whereIn('round_id', ProgramRound::where('program_id', $program->id)->select('id'))
                ->exists())
        );
    }

    private function allow(bool $ok): void
    {
        if (!$ok) throw new AuthorizationException(self::DENIED);
    }
}
