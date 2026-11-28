<?php

namespace App\Service\Organization;

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\User;
use App\Models\Kyc\KycVerification;
use App\Models\Organizations\Organization;
use App\Models\Organizations\OrganizationProvider;
use App\Models\Programs\Monitoring\MESiteVisit;
use App\Models\Programs\Program;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\Programs\Rounds\ReviewerApplicationAssignment;
use App\Models\Programs\Rounds\RoundReviewer;
use App\Support\ApiContract;
use App\Support\CollectionList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OrganizationReviewerService
{
    /**
     * Return internal and external reviewers with their assignment summaries.
     */
    public function forOrganization(Organization $organization): Collection
    {
        $internalReviewerMemberships = $this->internalReviewerMemberships($organization);
        $externalReviewers = $this->externalReviewers($organization);
        $thirdPartyProviders = $this->thirdPartyProviders($organization);

        $reviewerIds = $internalReviewerMemberships
            ->pluck('user_id')
            ->merge($externalReviewers->pluck('id'))
            ->merge($thirdPartyProviders->pluck('id'))
            ->unique()
            ->values();

        [$roundIdsByReviewer, $siteVisitIdsByReviewer] = $this->assignmentIds($reviewerIds);
        $reviewCounts = $this->reviewCounts($organization, $reviewerIds);

        return $internalReviewerMemberships
            ->map(fn (OrganizationUserRole $membership) => $this->reviewerPayload(
                $membership->user,
                'internal',
                $roundIdsByReviewer,
                $siteVisitIdsByReviewer,
                $membership,
            ))
            ->concat($externalReviewers->map(fn (User $reviewer) => $this->reviewerPayload(
                $reviewer,
                'external',
                $roundIdsByReviewer,
                $siteVisitIdsByReviewer,
            )))
            ->concat($thirdPartyProviders->map(fn (User $provider) => $this->reviewerPayload(
                $provider,
                'third_party',
                $roundIdsByReviewer,
                $siteVisitIdsByReviewer,
            )))
            ->map(fn (array $row) => $row + [
                'reviewed_count' => $reviewCounts[$row['id']]['reviewed'] ?? 0,
                'awaiting_count' => $reviewCounts[$row['id']]['awaiting'] ?? 0,
            ])
            ->values();
    }

    public const STATUS_KEYS = ['active', 'pending', 'deactivated'];
    public const TYPE_KEYS = ['internal', 'external', 'third_party'];
    public const WORK_KEYS = ['pending', 'done', 'none'];

    /** True when the request asks for the paged / searchable form (the old full list stays for the pickers). */
    public static function wantsList(Request $request): bool
    {
        return ApiContract::wantsPagination($request) || $request->boolean('export')
            || $request->filled('search') || $request->filled('filters') || $request->filled('sort');
    }

    /**
     * The Business Coaches page's list: the same rows as forOrganization() with the keys the table filters on
     * (status_key, work_key, the counts), searched, filtered, sorted and paged on the server, with the card totals
     * over every coach of the organization. The rows come from three tables, so they are listed in memory.
     */
    public function respondList(Organization $organization, Request $request): JsonResponse
    {
        $coaches = $this->forOrganization($organization);
        // Whose identity (KYC) check went through, in one query
        $verified = KycVerification::whereIn('user_id', $coaches->pluck('id'))->where('status', 'verified')->pluck('user_id')->map(fn ($id) => (int) $id)->flip();
        $all = $coaches->map(function (array $row) use ($verified) {
            // Applications handed to the coach in this organization's programs: reviewed once their assignment is
            // completed, awaiting while it is not started or in review
            $reviewed = (int) ($row['reviewed_count'] ?? 0);
            $pending = (int) ($row['awaiting_count'] ?? 0);
            $assigned = $reviewed + $pending;

            return $row + [
                'name' => trim(implode(' ', array_filter([$row['first_name'], $row['last_name']]))) ?: (string) $row['display_name'],
                'status_key' => ($row['membership']['status'] ?? null) === 'revoked' ? 'deactivated' : ($row['active'] ? 'active' : 'pending'),
                'assigned_count' => $assigned,
                'reviewed_count' => $reviewed,
                'pending_count' => $pending,
                'site_visit_count' => (int) ($row['site_visits_assigned']['count'] ?? 0),
                'work_key' => ! $assigned ? 'none' : ($pending ? 'pending' : 'done'),
                'verified' => $verified->has((int) $row['id']),
            ];
        });

        $list = CollectionList::for($all, $request)
            ->search(fn ($r) => implode(' ', [$r['name'], $r['display_name'], $r['email'], $r['phone']]))
            ->filter('id', fn ($r) => $r['id'])
            // Described filters: the Business Coaches page's panel, chips and URL state come from `filter_schema`
            ->options('status', fn ($r) => $r['status_key'], label: 'app:status', values: self::STATUS_KEYS,
                optionLabels: ['active' => 'app:active', 'pending' => 'app:pending2', 'deactivated' => 'app:deactivated'])
            ->options('type', fn ($r) => $r['reviewer_type'], label: 'grant:userManagement.listUi.coaches.colType', fallback: 'Type', values: self::TYPE_KEYS,
                optionLabels: ['internal' => 'grant:userManagement.listUi.coaches.typeInternal', 'external' => 'grant:userManagement.listUi.coaches.typeExternal', 'third_party' => 'grant:userManagement.listUi.coaches.typeThirdParty'])
            ->options('work', fn ($r) => $r['work_key'], label: 'grant:userManagement.listUi.coaches.workload', fallback: 'Workload', values: self::WORK_KEYS,
                optionLabels: ['pending' => 'grant:userManagement.listUi.coaches.workPending', 'done' => 'grant:userManagement.listUi.coaches.workDone', 'none' => 'grant:userManagement.listUi.coaches.workNone'])
            ->numberRange('applications', fn ($r) => $r['assigned_count'], label: 'app:listFilterApplicationsAssigned', fallback: 'Applications assigned')
            ->numberRange('reviewed', fn ($r) => $r['reviewed_count'], label: 'app:listFilterApplicationsReviewed', fallback: 'Applications reviewed')
            ->numberRange('rounds', fn ($r) => (int) ($r['program_rounds_assigned']['count'] ?? 0), label: 'app:listFilterRoundsAssigned', fallback: 'Rounds assigned')
            ->boolean('site_visits', fn ($r) => $r['site_visit_count'] > 0, label: 'app:listFilterHasSiteVisits', fallback: 'Has site visits')
            ->boolean('verified', fn ($r) => $r['verified'], label: 'app:verified')
            ->dateRange('added', fn ($r) => $r['created_at'], label: 'app:added')
            ->sortable([
                'name' => fn ($r) => mb_strtolower($r['name']),
                'type' => fn ($r) => $r['reviewer_type'],
                'reviews' => fn ($r) => $r['reviewed_count'] * 100000 + $r['assigned_count'],
                'added' => fn ($r) => (string) $r['created_at'],
            ], default: 'added');

        [$rows, $page] = $list->fetch();

        $data = [
            'items' => $rows->values(),
            'facets' => $list->facets(['status', 'type', 'work']),
            'filter_schema' => $list->filterSchema(),
            'stats' => [
                'total' => $all->count(),
                'active' => $all->where('status_key', 'active')->count(),
                'pending' => $all->where('status_key', 'pending')->count(),
                'deactivated' => $all->where('status_key', 'deactivated')->count(),
                'assigned' => $all->sum('assigned_count'),
                'reviewed' => $all->sum('reviewed_count'),
                'awaiting' => $all->sum('pending_count'),
                'site_visits' => $all->sum('site_visit_count'),
                'with_site_visits' => $all->where('site_visit_count', '>', 0)->count(),
                'no_work' => $all->where('work_key', 'none')->count(),
            ],
            'export_truncated' => false,
        ];
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }

        return response()->json([
            'organization_id' => $organization->id,
            'reviewers' => $rows->values(),
            'data' => $data,
        ]);
    }

    private function internalReviewerMemberships(Organization $organization): Collection
    {
        return OrganizationUserRole::query()
            ->select(['id', 'organization_id', 'user_id', 'role_id', 'status', 'accepted_at', 'created_at'])
            ->where('organization_id', $organization->id)
            // Deactivated reviewers stay in the list (marked inactive) so they can be activated again
            ->whereIn('status', ['active', 'revoked'])
            ->whereHas('role', fn ($query) => $query->whereIn('name', ['super_admin', 'super-admin', 'editor', 'viewer', 'internal_reviewer']))
            ->whereHas('user', fn ($query) => $query->where('user_type_id', 4))
            ->with([
                'role:id,name,access_types',
                'user' => fn ($query) => $query
                    ->without('user_type')
                    ->select(['id', 'user_type_id', 'first_name', 'last_name', 'display_name', 'email', 'phone', 'image', 'organization_id', 'created_at']),
            ])
            ->orderBy('created_at')
            ->get();
    }

    /** Tujitume service providers the organization added as third-party reviewers/auditors. */
    private function thirdPartyProviders(Organization $organization): Collection
    {
        if (! OrganizationProvider::available()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', OrganizationProvider::where('organization_id', $organization->id)->select('user_id'))
            ->where('user_type_id', 3)
            ->orderBy('first_name')
            ->get(['id', 'user_type_id', 'first_name', 'last_name', 'display_name', 'email', 'phone', 'image', 'organization_id', 'password', 'created_at']);
    }

    private function externalReviewers(Organization $organization): Collection
    {
        // External reviewers are linked directly to users.organization_id and
        // intentionally have no organization_user_roles record.
        return User::query()
            // ->without('user_type')
            ->where('organization_id', $organization->id)
            ->where('user_type_id', 6)
            ->orderBy('created_at')
            ->get(['id', 'user_type_id', 'first_name', 'last_name', 'display_name', 'email', 'phone', 'image', 'organization_id', 'password', 'created_at']);
    }

    private function assignmentIds(Collection $reviewerIds): array
    {
        if ($reviewerIds->isEmpty()) {
            return [collect(), collect()];
        }

        $roundIdsByReviewer = RoundReviewer::query()
            ->whereIn('user_id', $reviewerIds)
            ->orderBy('round_id')
            ->get(['user_id', 'round_id'])
            ->groupBy('user_id')
            ->map(fn (Collection $assignments) => $assignments->pluck('round_id')->values()->all());

        $siteVisitIdsByReviewer = MESiteVisit::query()
            ->whereIn('reviewer_id', $reviewerIds)
            ->orderBy('id')
            ->get(['reviewer_id', 'id'])
            ->groupBy('reviewer_id')
            ->map(fn (Collection $assignments) => $assignments->pluck('id')->values()->all());

        return [$roundIdsByReviewer, $siteVisitIdsByReviewer];
    }

    /**
     * Per coach, their application assignments in this organization's programs, in one query:
     * reviewed = completed, awaiting = assigned (not started) or in review.
     *
     * @return array<int, array{reviewed: int, awaiting: int}>
     */
    private function reviewCounts(Organization $organization, Collection $reviewerIds): array
    {
        if ($reviewerIds->isEmpty()) {
            return [];
        }

        $programOwners = User::query()->where('organization_id', $organization->id)->select('id');
        $rounds = ProgramRound::query()->whereIn('program_id', Program::query()
            ->where(fn ($q) => $q->whereIn('user_id', $programOwners)->orWhere('user_id', $organization->owner_user_id))
            ->select('id'))->select('id');

        return ReviewerApplicationAssignment::query()
            ->whereIn('reviewer_id', $reviewerIds)
            ->whereIn('round_id', $rounds)
            ->selectRaw("reviewer_id, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as reviewed, SUM(CASE WHEN status IN ('assigned', 'in_review') THEN 1 ELSE 0 END) as awaiting")
            ->groupBy('reviewer_id')
            ->toBase()->get()
            ->mapWithKeys(fn ($r) => [(int) $r->reviewer_id => ['reviewed' => (int) $r->reviewed, 'awaiting' => (int) $r->awaiting]])
            ->all();
    }

    private function reviewerPayload(
        User $reviewer,
        string $reviewerType,
        Collection $roundIdsByReviewer,
        Collection $siteVisitIdsByReviewer,
        ?OrganizationUserRole $membership = null,
    ): array {
        $roundIds = $roundIdsByReviewer->get($reviewer->id, []);
        $siteVisitIds = $siteVisitIdsByReviewer->get($reviewer->id, []);

        return [
            'id' => $reviewer->id,
            'user_type_id' => $reviewer->user_type_id,
            'reviewer_type' => $reviewerType,
            'first_name' => $reviewer->first_name,
            'last_name' => $reviewer->last_name,
            'display_name' => $reviewer->display_name,
            'email' => $reviewer->email,
            'phone' => $reviewer->phone,
            'image' => $reviewer->image,
            'organization_id' => $membership?->organization_id ?? $reviewer->organization_id,
            // Internal reviewers are only listed once their membership is active. An external reviewer has no
            // membership: they are invited without a password, so having one means the invitation was accepted.
            'active' => $membership ? $membership->status === 'active' : ! empty($reviewer->getAttributes()['password'] ?? null),
            'role' => $membership ? [
                'id' => $membership->role->id,
                'name' => $membership->role->name,
                'access_types' => $membership->role->access_types,
            ] : null,
            'membership' => $membership ? [
                'id' => $membership->id,
                'status' => $membership->status,
                'accepted_at' => $membership->accepted_at?->toISOString(),
            ] : null,
            'program_rounds_assigned' => [
                'round_ids' => $roundIds,
                'count' => count($roundIds),
            ],
            'site_visits_assigned' => [
                'site_visit_ids' => $siteVisitIds,
                'count' => count($siteVisitIds),
            ],
            // When they were added: the membership for a team member, the account for everyone else
            'created_at' => ($membership?->created_at ?? $reviewer->created_at)?->toISOString(),
        ];
    }
}
