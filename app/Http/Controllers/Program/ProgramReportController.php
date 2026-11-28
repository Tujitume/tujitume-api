<?php

namespace App\Http\Controllers\Program;

use App\Http\Controllers\Controller;
use App\Models\ApplicationRoundHistory;
use App\Models\Programs\Disbursement;
use App\Models\Programs\Program;
use App\Models\Programs\ProgramApplication;
use App\Models\Programs\Rounds\ApplicationScore;
use App\Models\Programs\Rounds\ProgramRound;
use App\Models\ReviewerOrder;
use App\Service\Program\ProgramAccess;
use App\Support\ApiContract;
use App\Support\CollectionList;
use App\Support\ListQuery;
use App\Support\ListShapes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * What the owner reads on the program page: who applied (and whether each application was reviewed), the program's
 * money ledger with a small analysis of where the funds went, and everything that happened in the program.
 * Read only; every list takes the shared search / filter / sort / page / export request (see docs/server-lists.md).
 */
class ProgramReportController extends Controller
{
    private function authorizeOwner(Program $program): void
    {
        (new ProgramAccess())->authorizeProgramOwner(auth()->user(), $program);
    }

    private static function person($user): string
    {
        return $user ? trim(implode(' ', array_filter([$user->first_name, $user->last_name], fn ($v) => $v && $v !== 'null'))) : '';
    }

    /** A date column (a Carbon, a string, or empty) as an ISO-8601 time, so the models' differing casts do not matter. */
    private static function iso($value): ?string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->toIso8601String() : null;
    }

    private function envelope(string $message, Collection $items, $page, array $extra = []): JsonResponse
    {
        $data = ['items' => $items->values()] + $extra;
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }

        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    // ── Applications ────────────────────────────────────────────────────────────────────────────

    /** GET programs/{program}/overview/applications */
    public function applications(Request $request, Program $program): JsonResponse
    {
        $this->authorizeOwner($program);

        $scored = fn (Builder $q) => $q->whereExists(fn ($s) => $s->from('application_scores')->whereColumn('application_scores.application_id', 'program_applications.id'));

        $base = ProgramApplication::query()
            ->where('program_id', $program->id)
            ->where('status', '!=', 'draft')
            ->with(['user:id,first_name,last_name,image', 'currentRound:id,round_number,round_name']);

        // Described filters (the tab's panel, chips and URL state come from `filter_schema`); every old key still works
        $sameProgram = fn () => ProgramApplication::query()->where('program_id', $program->id)->where('status', '!=', 'draft');
        $kycVerified = fn (Builder $q) => $q->whereExists(fn ($k) => $k->from('kyc_verifications')
            ->whereColumn('kyc_verifications.user_id', 'program_applications.user_id')->where('kyc_verifications.status', 'verified'));
        $awarded = fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereIn('program_applications.status', ['awarded', 'in_monitoring', 'completed'])->orWhereRaw('COALESCE(program_applications.awarded_amount, 0) > 0')); // COALESCE: "no" must not drop empty amounts
        $yesNo = fn (callable $where) => fn (Builder $q, bool $yes) => $yes ? $where($q) : $q->whereNot(fn (Builder $n) => $where($n));
        $label = fn (string $key) => 'grant:view.reports.applications.'.$key;

        $list = ListQuery::for($base, $request)
            ->search(['startup_name', 'contact_person_name', 'contact_person_email', 'email', 'sector', 'headquarters_location', 'user.first_name', 'user.last_name'])
            ->filter('id', 'id') // one row by id (a link to a row on another page)
            ->options('status', 'status', label: $label('filterStatus'))
            ->entity('round', 'current_round_id', fn (array $ids) => ProgramRound::whereIn('id', $ids)->get(['id', 'round_number', 'round_name'])
                ->mapWithKeys(fn ($r) => [$r->id => 'Round '.$r->round_number.($r->round_name ? ' · '.$r->round_name : '')])->all(), label: $label('filterRound'), searchable: null)
            ->options('review', function (Builder $q, array $values) use ($scored) {
                if (in_array('reviewed', $values, true) && ! in_array('not_reviewed', $values, true)) {
                    $scored($q);
                } elseif (in_array('not_reviewed', $values, true) && ! in_array('reviewed', $values, true)) {
                    $q->whereNot(fn (Builder $n) => $scored($n));
                }
            }, label: $label('filterReview'), values: ['reviewed', 'not_reviewed'],
                optionLabels: ['reviewed' => $label('reviewed'), 'not_reviewed' => $label('notReviewed')],
                countBy: fn (Builder $q) => [
                    ['value' => 'reviewed', 'count' => (clone $q)->tap($scored)->toBase()->count()],
                    ['value' => 'not_reviewed', 'count' => (clone $q)->whereNot(fn (Builder $n) => $scored($n))->toBase()->count()],
                ])
            ->options('stage', 'stage', label: 'app:stage')
            ->options('sector', ListShapes::derivedFilter($sameProgram(), ['sector'], fn ($a) => ListShapes::clean($a->sector)), label: $label('filterSector'),
                countBy: fn (Builder $q) => ListShapes::derivedFacet($q, ['sector'], fn ($a) => ListShapes::clean($a->sector)))
            ->options('location', ListShapes::derivedFilter($sameProgram(), ['headquarters_location'], fn ($a) => ListShapes::place($a->headquarters_location)), label: 'app:location',
                countBy: fn (Builder $q) => ListShapes::derivedFacet($q, ['headquarters_location'], fn ($a) => ListShapes::place($a->headquarters_location)))
            ->numberRange('match', 'match_score', label: 'app:matchScore')
            ->options('match_tier', \App\Support\MatchTier::sql('match_score'), label: 'app:matchTierLabel', fallback: 'Match tier', values: \App\Support\MatchTier::KEYS)
            ->numberRange('score', 'average_score', label: 'app:averageScore')
            ->numberRange('requested', 'total_amount_requested', label: 'app:amountRequested')
            ->boolean('awarded', $yesNo($awarded), label: 'app:awarded')
            ->boolean('documents', $yesNo(fn (Builder $q) => $q->whereHas('roundDocuments')), label: 'app:listFilterHasDocuments', fallback: 'Has documents')
            ->boolean('kyc', $yesNo($kycVerified), label: 'app:listFilterKycVerified', fallback: 'KYC verified')
            ->dateRange('applied', 'created_at', label: $label('filterApplied'))
            ->sortable(['applied' => 'created_at', 'name' => 'startup_name', 'match' => 'match_score', 'score' => 'average_score', 'status' => 'status'], default: '-applied');

        [$rows, $page] = $list->fetch();

        $reviews = ApplicationScore::query()
            ->whereIn('application_id', $rows->pluck('id'))
            ->selectRaw('application_id, count(*) as reviews')
            ->groupBy('application_id')
            ->pluck('reviews', 'application_id');

        $items = $rows->map(fn (ProgramApplication $a) => [
            'id' => $a->id,
            'startup_name' => $a->startup_name,
            'applicant_name' => $a->applicantProfile()['applicant_name'],
            'applicant_image' => $a->applicantProfile()['applicant_image'],
            'contact_person_name' => $a->contact_person_name,
            'contact_person_email' => $a->contact_person_email ?: $a->email,
            'sector' => $a->sector,
            'location' => $a->headquarters_location,
            'stage' => $a->stage,
            'status' => ApiContract::statusMeta('program_application', $a->status),
            'round' => $a->currentRound ? ['id' => $a->currentRound->id, 'number' => $a->currentRound->round_number, 'name' => $a->currentRound->round_name] : null,
            'match_score' => $a->match_score,
            'average_score' => $a->average_score,
            'reviews_count' => (int) ($reviews[$a->id] ?? 0),
            'reviewed' => ((int) ($reviews[$a->id] ?? 0)) > 0,
            'amount_requested' => $a->total_amount_requested,
            'amount_awarded' => $a->awarded_amount,
            'applied_at' => self::iso($a->created_at),
        ]);

        $facets = $list->facets(['round', 'status', 'match_tier']);
        $facets['sector'] = ListShapes::derivedFacet($list->query('sector', sorted: false), ['sector'], fn ($a) => ListShapes::clean($a->sector));
        $reviewedCount = $list->query('review', sorted: false)->reorder()->tap($scored)->toBase()->count();
        $unreviewedCount = $list->query('review', sorted: false)->reorder()->whereNot(fn (Builder $n) => $scored($n))->toBase()->count();
        $facets['review'] = array_values(array_filter([
            ['value' => 'reviewed', 'count' => $reviewedCount],
            ['value' => 'not_reviewed', 'count' => $unreviewedCount],
        ], fn ($o) => $o['count'] > 0));

        $all = ProgramApplication::where('program_id', $program->id)->where('status', '!=', 'draft');
        $stats = [
            'total' => (clone $all)->count(),
            'reviewed' => (clone $all)->tap($scored)->count(),
        ];
        $stats['not_reviewed'] = $stats['total'] - $stats['reviewed'];

        return $this->envelope('Applications retrieved', $items, $page, [
            'facets' => $facets,
            'filter_schema' => $list->filterSchema(),
            'stats' => $stats,
            'export_truncated' => $list->wantsExport() ? $list->exportTruncated() : false,
        ]);
    }

    // ── Wallet ──────────────────────────────────────────────────────────────────────────────────

    private const PAID = ['completed'];
    private const FAILED = ['failed', 'reversed'];

    private static function statusGroup(string $status): string
    {
        return in_array($status, self::PAID, true) ? 'paid' : (in_array($status, self::FAILED, true) ? 'failed' : 'pending');
    }

    /** Every recorded payment out of the program: supplier / business disbursements and Business Coach fees. */
    private function ledger(Program $program): Collection
    {
        $rows = collect();

        if ($wallet = $program->wallet) {
            Disbursement::query()
                ->where('wallet_id', $wallet->id)
                ->with(['milestone:id,app_id,sequence_order,title', 'milestone.application:id,startup_name', 'supplier.supplierDirectory'])
                ->get()
                ->each(function (Disbursement $d) use ($rows) {
                    $business = $d->recipient_type === 'business_owner';
                    $application = $d->milestone?->application?->startup_name;
                    $rows->push([
                        'key' => 'disbursement-'.$d->id,
                        'kind' => $business ? 'business' : 'supplier',
                        'at' => self::iso($d->disbursed_at ?? $d->created_at),
                        'label' => trim(($d->milestone ? 'Milestone '.$d->milestone->sequence_order : 'Milestone').($application ? ' · '.$application : '')),
                        'recipient' => $business ? ($application ?: self::person($d->recipientUser)) : ($d->supplier?->supplierDirectory?->legal_name ?? ''),
                        'amount' => (float) $d->amount,
                        // the transfer fee the program paid on top (ISSUE-22), shown with the payout (ISSUE-31)
                        'fee' => (float) ($d->fee_amount ?? 0),
                        'currency' => $d->currency,
                        'status' => $d->status,
                        'status_group' => self::statusGroup($d->status),
                        'reference' => $d->payment_reference,
                        'application_id' => $d->milestone?->app_id,
                    ]);
                });
        }

        ReviewerOrder::query()
            ->where('program_id', $program->id)
            ->where('fee_amount', '>', 0)
            ->where('acceptance_status', '!=', 'declined')
            ->with(['reviewer:id,first_name,last_name', 'round:id,round_number,round_name'])
            ->get()
            ->each(function (ReviewerOrder $o) use ($rows) {
                $rows->push([
                    'key' => 'coach-'.$o->id,
                    'kind' => 'business_coach',
                    'at' => self::iso($o->paid_at ?? $o->approved_at ?? $o->created_at),
                    'label' => $o->order_type === 'site_visit' ? 'Site visit' : 'Round '.($o->round?->round_number ?? '').' review',
                    'recipient' => self::person($o->reviewer),
                    'amount' => (float) $o->fee_amount,
                    'fee' => 0.0,
                    'currency' => $o->currency,
                    'status' => $o->payment_status,
                    'status_group' => self::statusGroup($o->payment_status === 'unpaid' ? 'pending' : $o->payment_status),
                    'reference' => $o->leg2_reference ?: $o->leg1_reference,
                    'application_id' => null,
                ]);
            });

        return $rows;
    }

    /** GET programs/{program}/overview/wallet */
    public function wallet(Request $request, Program $program): JsonResponse
    {
        $this->authorizeOwner($program);
        $program->loadMissing('wallet');

        $ledger = $this->ledger($program);

        $list = CollectionList::for($ledger, $request)
            ->search(fn ($r) => implode(' ', [$r['label'], $r['recipient'], $r['reference'], $r['kind'], $r['status']]))
            // Described filters (the ledger's panel comes from `filter_schema`)
            ->options('kind', fn ($r) => $r['kind'], label: 'grant:view.reports.wallet.filterType', values: ['supplier', 'business', 'business_coach'],
                optionLabels: array_combine(['supplier', 'business', 'business_coach'], array_map(fn ($k) => 'grant:view.reports.wallet.kind.'.$k, ['supplier', 'business', 'business_coach'])))
            ->options('status', fn ($r) => $r['status_group'], label: 'grant:view.reports.wallet.filterStatus', values: ['paid', 'pending', 'failed'],
                optionLabels: array_combine(['paid', 'pending', 'failed'], array_map(fn ($k) => 'grant:view.reports.wallet.status.'.$k, ['paid', 'pending', 'failed'])))
            ->options('recipient', fn ($r) => $r['recipient'] ?: null, label: 'app:listFilterRecipient', fallback: 'Recipient', searchable: true)
            ->options('currency', fn ($r) => $r['currency'] ?: null, label: 'app:currency')
            ->numberRange('amount', fn ($r) => $r['amount'], label: 'app:amount')
            ->boolean('business_linked', fn ($r) => $r['application_id'] !== null, label: 'app:listFilterForABusiness', fallback: 'For a business')
            ->dateRange('date', fn ($r) => $r['at'], label: 'grant:view.reports.wallet.filterDate')
            ->sortable(['date' => fn ($r) => $r['at'], 'amount' => fn ($r) => $r['amount'], 'kind' => fn ($r) => $r['kind'], 'status' => fn ($r) => $r['status_group']], default: '-date');

        [$rows, $page] = $list->fetch();

        // Where the money went and what is still owed, over the whole ledger (not the search)
        $by = fn (string $kind) => [
            'paid' => round($ledger->where('kind', $kind)->where('status_group', 'paid')->sum('amount'), 2),
            'pending' => round($ledger->where('kind', $kind)->where('status_group', 'pending')->sum('amount'), 2),
            'count' => $ledger->where('kind', $kind)->where('status_group', '!=', 'failed')->count(),
        ];
        $wallet = $program->wallet;

        return $this->envelope('Wallet report retrieved', $rows, $page, [
            'wallet' => $wallet ? [
                'id' => $wallet->id,
                'status' => $wallet->status,
                'currency' => $wallet->currency,
                'balance' => (float) $wallet->balance,
                'total_deposited' => (float) $wallet->total_deposited,
                'total_disbursed' => (float) $wallet->total_disbursed,
                'total_reserved' => (float) $wallet->total_reserved,
            ] : null,
            'analytics' => [
                'businesses' => $by('business'),
                'suppliers' => $by('supplier'),
                'business_coaches' => $by('business_coach'),
                'paid_total' => round($ledger->where('status_group', 'paid')->sum('amount'), 2),
                // ISSUE-31: the fees the program paid on top of its payouts, and payouts + fees (what left the wallet)
                'fees_total' => round($ledger->where('status_group', 'paid')->sum('fee'), 2),
                'paid_with_fees_total' => round($ledger->where('status_group', 'paid')->sum(fn ($r) => $r['amount'] + $r['fee']), 2),
                'pending_total' => round($ledger->where('status_group', 'pending')->sum('amount'), 2),
                // Deposits are only kept as a running total on the wallet, so they are not itemised in the ledger
                'deposits_itemised' => false,
            ],
            'facets' => $list->facets(['kind', 'status']),
            'filter_schema' => $list->filterSchema(),
            'export_truncated' => $list->wantsExport() ? $list->exportTruncated() : false,
        ]);
    }

    // ── Activity ────────────────────────────────────────────────────────────────────────────────

    /** GET programs/{program}/overview/activity */
    public function activity(Request $request, Program $program): JsonResponse
    {
        $this->authorizeOwner($program);

        $events = collect();
        // The event's own details ($more) override the empty defaults (array + would keep the nulls instead)
        $event = fn (string $key, string $type, string $group, $at, array $more = []) => $events->push(array_merge([
            'key' => $key, 'type' => $type, 'group' => $group, 'at' => self::iso($at),
            'actor' => null, 'subject' => null, 'amount' => null, 'currency' => null, 'round_number' => null, 'application_id' => null,
        ], $more));

        $event('program', 'program_created', 'program', $program->created_at, ['subject' => $program->program_title, 'actor' => self::person($program->owner)]);

        $rounds = ProgramRound::where('program_id', $program->id)->get(['id', 'round_number', 'round_name', 'created_at']);
        $rounds->each(fn ($r) => $event('round-'.$r->id, 'round_created', 'rounds', $r->created_at, ['subject' => $r->round_name, 'round_number' => $r->round_number]));
        $roundNumbers = $rounds->pluck('round_number', 'id');

        ProgramApplication::where('program_id', $program->id)->where('status', '!=', 'draft')->latest()->get(['id', 'startup_name', 'created_at'])
            ->each(fn ($a) => $event('app-'.$a->id, 'application_submitted', 'applications', $a->created_at, ['subject' => $a->startup_name, 'application_id' => $a->id]));

        ApplicationRoundHistory::whereIn('round_id', $rounds->pluck('id'))
            ->whereIn('outcome', ['advanced', 'not_selected', 'awarded'])->whereNotNull('exited_at')
            ->with('application:id,startup_name')->latest('exited_at')->get()
            ->each(fn ($h) => $event('outcome-'.$h->id, 'round_'.$h->outcome, 'applications', $h->exited_at, [
                'subject' => $h->application?->startup_name, 'application_id' => $h->application_id, 'round_number' => $roundNumbers[$h->round_id] ?? $h->round_number,
            ]));

        ApplicationScore::whereIn('round_id', $rounds->pluck('id'))
            ->with(['application:id,startup_name', 'reviewer:id,first_name,last_name'])->latest('scored_at')->get()
            ->each(fn ($s) => $event('score-'.$s->id, 'application_scored', 'reviews', $s->scored_at, [
                'subject' => $s->application?->startup_name, 'actor' => self::person($s->reviewer), 'application_id' => $s->application_id, 'round_number' => $roundNumbers[$s->round_id] ?? null,
            ]));

        $this->ledger($program)->each(fn ($row) => $event($row['key'], $row['status_group'] === 'paid' ? 'payment_made' : ($row['status_group'] === 'failed' ? 'payment_failed' : 'payment_pending'), 'money', $row['at'], [
            'subject' => $row['recipient'] ?: $row['label'], 'amount' => $row['amount'], 'currency' => $row['currency'], 'application_id' => $row['application_id'], 'actor' => $row['kind'],
        ]));

        $list = CollectionList::for($events->filter(fn ($e) => $e['at']), $request)
            ->search(fn ($e) => implode(' ', [$e['subject'], $e['actor'], $e['type']]))
            // Described filters (the feed's panel comes from `filter_schema`)
            ->options('group', fn ($e) => $e['group'], label: 'grant:view.reports.activity.filterGroup', values: ['program', 'rounds', 'applications', 'reviews', 'money'],
                optionLabels: array_combine(['program', 'rounds', 'applications', 'reviews', 'money'], array_map(fn ($g) => 'grant:view.reports.activity.groups.'.$g, ['program', 'rounds', 'applications', 'reviews', 'money'])))
            ->options('round', fn ($e) => $e['round_number'], label: 'app:round2',
                optionLabels: fn (array $numbers) => collect($numbers)->mapWithKeys(fn ($n) => [$n => 'Round '.$n])->all())
            ->options('coach', fn ($e) => $e['type'] === 'application_scored' ? ($e['actor'] ?: null) : null, label: 'app:reviewer', fallback: 'Business Coach', searchable: true)
            ->options('business', fn ($e) => $e['application_id'] ? ($e['subject'] ?: null) : null, label: 'app:listFilterBusiness', fallback: 'Business', searchable: true)
            ->numberRange('amount', fn ($e) => $e['amount'], label: 'app:amount')
            ->dateRange('date', fn ($e) => $e['at'], label: 'grant:view.reports.activity.filterDate')
            ->sortable(['date' => fn ($e) => $e['at']], default: '-date');

        [$rows, $page] = $list->fetch();

        return $this->envelope('Activity retrieved', $rows, $page, [
            'facets' => $list->facets(['group']),
            'filter_schema' => $list->filterSchema(),
            'export_truncated' => $list->wantsExport() ? $list->exportTruncated() : false,
        ]);
    }
}
