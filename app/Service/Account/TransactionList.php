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

    public function __construct(private int $accountId)
    {
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

        $data = [
            'items' => $rows->values(),
            'facets' => $this->facets($list),
            'stats' => $this->stats(),
            'account_id' => $this->accountId,
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
        return Transactions::query()->with(['recipient', 'user'])
            ->where(fn (Builder $q) => $q->where('user_id', $this->accountId)->orWhere('recipient_id', $this->accountId));
    }

    private function list(Request $request): ListQuery
    {
        return ListQuery::for($this->base(), $request)
            ->search(['id', 'reference_id', 'type', 'method', 'status', 'user.first_name', 'user.last_name', 'recipient.first_name', 'recipient.last_name'])
            ->filter('id', 'id')
            ->filter('type', 'type')
            ->filter('status', 'status')
            ->filter('method', 'method')
            ->filter('direction', function (Builder $q, array $values) {
                $q->where(function (Builder $any) use ($values) {
                    if (in_array('credit', $values, true)) {
                        $any->orWhere(fn (Builder $c) => $this->whereCredit($c));
                    }
                    if (in_array('debit', $values, true)) {
                        $any->orWhere(fn (Builder $d) => $this->whereDebit($d));
                    }
                });
            })
            ->dateRange('date', 'created_at')
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

    private function directionOf(Transactions $t): string
    {
        return $t->direction === 'debit' && (int) $t->recipient_id === $this->accountId ? 'credit' : $t->direction;
    }

    /** Option counts per filter, each counted with the search and the other filters applied. */
    private function facets(ListQuery $list): array
    {
        $facets = $list->facets(['type', 'status', 'method']);

        $counts = $list->query('direction', sorted: false)->reorder()
            ->selectRaw('SUM(CASE WHEN direction = ? AND recipient_id = ? THEN 1 WHEN direction = ? THEN 1 ELSE 0 END) as credit_count', ['debit', $this->accountId, 'credit'])
            ->selectRaw('COUNT(*) as total')
            ->toBase()->first();
        $credit = (int) ($counts->credit_count ?? 0);
        $facets['direction'] = array_values(array_filter([
            ['value' => 'credit', 'count' => $credit],
            ['value' => 'debit', 'count' => (int) ($counts->total ?? 0) - $credit],
        ], fn ($o) => $o['count'] > 0));

        return $facets;
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

        $rows = Transactions::query()
            ->where(fn (Builder $q) => $q->where('user_id', $me)->orWhere('recipient_id', $me))
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
