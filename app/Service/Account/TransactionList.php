<?php

namespace App\Service\Account;

use App\Models\Finance\Transactions;
use App\Support\ApiContract;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The wallet's transaction list for the Transactions page: a page of rows with search, filters, sort and export on the
 * database, the option counts for the filter popover, and the totals for the three summary cards over ALL of the account's
 * transactions (the cards do not change with the search).
 *
 * A transaction the account RECEIVED is stored as the sender's debit; the account sees it as money in, so "direction" is
 * worked out per account everywhere here (list rows, the direction filter, the counts and the totals).
 */
class TransactionList
{
    /** The money amount a person sees: the net amount, or the gross when the net is empty. */
    private const AMOUNT = 'COALESCE(NULLIF(transactions.net_amount, 0), transactions.gross_amount)';

    public const SCOPES = ['all', 'personal', 'organization'];

    /**
     * $scope (owner, 28 Nov): 'personal' = the person's own money (My Wallet), 'organization' = the organization
     * wallet's money ($organizationId), 'all' = both (My Transactions). Without an organization, everything is personal.
     */
    public function __construct(private int $accountId, private ?int $organizationId = null, private string $scope = 'all')
    {
        if (! in_array($this->scope, self::SCOPES, true)) $this->scope = 'all';
        if (! $this->organizationId && $this->scope === 'organization') $this->organizationId = -1;
    }

    /** The rows this list covers, for the given scope. */
    private function scoped(Builder $q): Builder
    {
        $personal = fn (Builder $p) => self::personalTo($p, $this->accountId);

        return match ($this->scope) {
            'organization' => $q->where('transactions.organization_id', $this->organizationId),
            'personal' => $q->where(fn (Builder $p) => $p->where($personal)),
            default => $this->organizationId
                ? $q->where(fn (Builder $any) => $any->where($personal)->orWhere('transactions.organization_id', $this->organizationId))
                : $q->where(fn (Builder $p) => $p->where($personal)),
        };
    }

    /** True when the request asks for the paged / searchable form (the old full list stays for everything else). */
    public static function wanted(Request $request): bool
    {
        return ApiContract::wantsPagination($request) || $request->boolean('export')
            || $request->filled('search') || $request->filled('filters') || $request->filled('sort');
    }

    public function respond(Request $request): JsonResponse
    {
        $list = $this->list($request);
        [$rows, $page] = $list->fetch();

        $rows->each(fn (Transactions $t) => $t->direction = $this->directionOf($t));
        $this->attachAbout($rows);

        $data = [
            'items' => $rows->values(),
            'facets' => $this->facets($list),
            'filter_schema' => $list->filterSchema(),
            'stats' => $this->stats(),
            'account_id' => $this->accountId,
            'scope' => $this->scope,
        ];
        if ($page) {
            $data['pagination'] = ApiContract::pagination($page);
        }
        if ($list->wantsExport()) {
            $data['export_truncated'] = $list->exportTruncated();
        }

        return response()->json(['success' => true, 'message' => 'Transactions retrieved', 'data' => $data]);
    }

    private function base(): Builder
    {
        return $this->scoped(Transactions::query()->with(['recipient', 'user']));
    }

    /**
     * The rows that are this account's money (C4 / ISSUE-49). A booked service is paid once into Tujitume's escrow
     * (service_fee: the customer's money out, not the provider's money in) and reaches the provider milestone by
     * milestone (service_milestone: the provider's money in, not the customer's: they already paid it).
     */
    /** Types whose recipient has a row of their own, so the payer's row is not shown to them too */
    // An investor's bid deposit and remaining 75% go to the platform's escrow, not to the business owner (who is paid by
    // the milestone releases, their own business_milestone rows): they used to show as the owner's money in
    private const OWN_SIDE_ONLY = ['service_fee', 'reviewer_payment_sent', 'reviewer_payment_received', 'pm_audit_fee', 'investment', 'investment_awaiting'];

    /**
     * Someone else's row that pays this account, unless this account has its own row for the same payment (a program
     * or capital milestone is stored once per side with the same reference): then only their own row shows, never both.
     */
    private static function paidToMe(Builder $q, int $accountId): Builder
    {
        return $q->where('recipient_id', $accountId)->whereNotIn('type', self::OWN_SIDE_ONLY)
            ->where(fn (Builder $other) => $other->whereNull('transactions.user_id')->orWhere('transactions.user_id', '!=', $accountId))
            ->where(fn (Builder $noOwn) => $noOwn->whereNull('transactions.reference_id')->orWhereNotExists(fn ($own) => $own->selectRaw('1')
                ->from('transactions as own')->where('own.user_id', $accountId)
                ->whereColumn('own.reference_id', 'transactions.reference_id')->whereColumn('own.type', 'transactions.type')));
    }

    /** A person's own money: what they made outside an organization wallet, and what was paid to them (also from one). */
    public static function personalTo(Builder $q, int $accountId): Builder
    {
        return $q->where(fn (Builder $mine) => $mine->where('user_id', $accountId)->where('type', '!=', 'service_milestone')->whereNull('transactions.organization_id'))
            ->orWhere(fn (Builder $toMe) => self::paidToMe($toMe, $accountId));
    }

    public static function scopeTo(Builder $q, int $accountId): Builder
    {
        // A Business Coach / site visit payout is stored twice, once per side (reviewer_payment_sent by the payer,
        // reviewer_payment_received by the coach): each side sees only its own row, never the other's as well
        return $q->where(fn (Builder $mine) => $mine->where('user_id', $accountId)->where('type', '!=', 'service_milestone'))
            ->orWhere(fn (Builder $toMe) => self::paidToMe($toMe, $accountId));
    }

    private function list(Request $request): ListQuery
    {
        return ListQuery::for($this->base(), $request)
            ->search(['id', 'reference_id', 'type', 'method', 'status', 'user.first_name', 'user.last_name', 'recipient.first_name', 'recipient.last_name'])
            ->filter('id', 'id')
            // Described filters: the Transactions panel, chips and URL state come from `filter_schema` (type, status and
            // method labels are the page's; money in / out and the currency are named here)
            ->options('direction', function (Builder $q, array $values) {
                $q->where(function (Builder $any) use ($values) {
                    if (in_array('credit', $values, true)) {
                        $any->orWhere(fn (Builder $c) => $this->whereCredit($c));
                    }
                    if (in_array('debit', $values, true)) {
                        $any->orWhere(fn (Builder $d) => $this->whereDebit($d));
                    }
                });
            }, label: 'shared:wallet.transactionsList.direction', fallback: 'Direction', values: ['credit', 'debit'],
                optionLabels: ['credit' => 'shared:wallet.transactionsList.moneyIn', 'debit' => 'shared:wallet.transactionsList.moneyOut'],
                countBy: fn (Builder $q) => $this->directionCounts($q))
            ->options('type', 'type', label: 'shared:wallet.transactionsList.type', fallback: 'Type')
            ->options('status', 'status', label: 'app:status')
            ->options('method', 'method', label: 'shared:wallet.transactionsList.method', fallback: 'Method')
            ->options('currency', DB::raw(self::CURRENCY), label: 'app:currency')
            ->numberRange('amount', DB::raw(self::AMOUNT), label: 'app:amount')
            ->numberRange('fee', 'fee_amount', label: 'app:fee')
            ->dateRange('date', 'created_at', label: 'app:date')
            ->sortable(['date' => 'created_at', 'type' => 'type', 'amount' => 'net_amount', 'status' => 'status'], default: '-date');
    }

    private function whereCredit(Builder $q): void
    {
        $q->where('direction', 'credit')->orWhere(fn (Builder $r) => $r->where('direction', 'debit')->where('recipient_id', $this->accountId));
    }

    private function whereDebit(Builder $q): void
    {
        $q->where('direction', 'debit')->where(fn (Builder $r) => $r->whereNull('recipient_id')->orWhere('recipient_id', '!=', $this->accountId));
    }

    /**
     * What each transaction was for, when it belongs to a service booking, as `about`: { kind: 'service_booking',
     * booking_id, service_id, service_name, milestone_title?, viewer: 'customer'|'provider' }. A service fee is the booking's
     * payment (service_bookings.payment_reference); a service milestone is a milestone paid out to the provider
     * (service_booking_milestones.release_reference). Looked up once per page; null when nothing matches.
     */
    private function attachAbout($rows): void
    {
        $references = $rows->pluck('reference_id')->filter()->unique()->values();
        if ($references->isEmpty()) {
            $rows->each(fn (Transactions $t) => $t->setAttribute('about', null));
            return;
        }
        $bookings = \App\Models\Services\ServiceBooking::whereIn('payment_reference', $references)
            ->get(['id', 'service_id', 'booker_id', 'service_owner_id', 'payment_reference'])->keyBy('payment_reference');
        $milestones = \App\Models\Services\ServiceBookingMilestone::whereIn('release_reference', $references)
            ->with('booking:id,service_id,booker_id,service_owner_id')->get()->keyBy('release_reference');
        $serviceIds = $bookings->pluck('service_id')->merge($milestones->pluck('booking.service_id'))->filter()->unique();
        $services = $serviceIds->isEmpty() ? collect() : \App\Models\Services\Services::whereIn('id', $serviceIds)->pluck('name', 'id');

        $rows->each(function (Transactions $t) use ($bookings, $milestones, $services) {
            $milestone = $t->reference_id ? $milestones->get($t->reference_id) : null;
            $booking = $t->reference_id ? ($bookings->get($t->reference_id) ?? $milestone?->booking) : null;
            $t->setAttribute('about', $booking ? [
                'kind' => 'service_booking',
                'booking_id' => (int) $booking->id,
                'service_id' => (int) $booking->service_id,
                'service_name' => $services[$booking->service_id] ?? null,
                'milestone_title' => $milestone?->title,
                // Which side of the booking this account is on: it opens the booking on its own bookings page
                'viewer' => (int) $booking->service_owner_id === $this->accountId ? 'provider' : 'customer',
            ] : null);
        });
    }

    private function directionOf(Transactions $t): string
    {
        return $t->direction === 'debit' && (int) $t->recipient_id === $this->accountId ? 'credit' : $t->direction;
    }

    /** Option counts per filter, each counted with the search and the other filters applied. */
    private function facets(ListQuery $list): array
    {
        $facets = $list->facets(['type', 'status', 'method']);

        $facets['direction'] = array_values(array_filter($this->directionCounts($list->query('direction', sorted: false)->reorder()), fn ($o) => $o['count'] > 0));

        return $facets;
    }

    /** [{value: credit|debit, count}] over $q, from this account's point of view. */
    private function directionCounts(Builder $q): array
    {
        $counts = $q->selectRaw('SUM(CASE WHEN direction = ? AND recipient_id = ? THEN 1 WHEN direction = ? THEN 1 ELSE 0 END) as credit_count', ['debit', $this->accountId, 'credit'])
            ->selectRaw('COUNT(*) as total')
            ->toBase()->first();
        $credit = (int) ($counts->credit_count ?? 0);

        return [['value' => 'credit', 'count' => $credit], ['value' => 'debit', 'count' => (int) ($counts->total ?? 0) - $credit]];
    }

    /** The currency of a row, as SQL: Stripe rows are USD, everything else KES (see Transactions::currencyForMethod). */
    private const CURRENCY = "CASE WHEN LOWER(COALESCE(transactions.method, '')) LIKE 'stripe%' THEN 'USD' ELSE 'KES' END";

    /**
     * Totals for the summary cards, over every transaction of the account (failed ones are not counted as money moved),
     * kept per currency: a KES total and a USD total must never be added together as if they were one currency.
     * Shape: { by_currency: { KES: {in_total, in_count, ...}, USD: {...} } }
     */
    private function stats(): array
    {
        $amount = self::AMOUNT;
        $credit = "(transactions.direction = 'credit' OR (transactions.direction = 'debit' AND COALESCE(transactions.recipient_id, 0) = ?))"; // COALESCE: a NULL recipient must read as "not me", never as unknown
        $month = now()->startOfMonth()->toDateTimeString();
        $me = $this->accountId;

        $rows = $this->scoped(Transactions::query())
            ->selectRaw(self::CURRENCY.' as cur')
            ->selectRaw("SUM(CASE WHEN status <> 'failed' AND $credit THEN 1 ELSE 0 END) as in_count", [$me])
            ->selectRaw("COALESCE(SUM(CASE WHEN status <> 'failed' AND $credit THEN $amount ELSE 0 END), 0) as in_total", [$me])
            ->selectRaw("COALESCE(SUM(CASE WHEN status <> 'failed' AND $credit AND transactions.created_at >= ? THEN $amount ELSE 0 END), 0) as in_month", [$me, $month])
            ->selectRaw("COALESCE(MAX(CASE WHEN status <> 'failed' AND $credit THEN $amount ELSE NULL END), 0) as in_largest", [$me])
            ->selectRaw("SUM(CASE WHEN status <> 'failed' AND NOT $credit THEN 1 ELSE 0 END) as out_count", [$me])
            ->selectRaw("COALESCE(SUM(CASE WHEN status <> 'failed' AND NOT $credit THEN $amount ELSE 0 END), 0) as out_total", [$me])
            ->selectRaw("COALESCE(SUM(CASE WHEN status <> 'failed' AND NOT $credit AND transactions.created_at >= ? THEN $amount ELSE 0 END), 0) as out_month", [$me, $month])
            ->selectRaw("COALESCE(SUM(CASE WHEN status <> 'failed' THEN transactions.fee_amount ELSE 0 END), 0) as fees")
            ->selectRaw("SUM(CASE WHEN status IN ('pending', 'processing') THEN 1 ELSE 0 END) as open_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('pending', 'processing') THEN $amount ELSE 0 END), 0) as open_total")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->selectRaw("SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded_count")
            ->groupBy(DB::raw(self::CURRENCY))
            ->toBase()->get();

        $byCurrency = [];
        foreach ($rows as $row) {
            $out = [];
            foreach ((array) $row as $key => $value) {
                if ($key === 'cur') {
                    continue;
                }
                $out[$key] = str_ends_with($key, '_count') ? (int) $value : round((float) $value, 2);
            }
            $byCurrency[$row->cur] = $out;
        }

        return ['by_currency' => $byCurrency];
    }
}
