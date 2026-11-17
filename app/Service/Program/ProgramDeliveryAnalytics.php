<?php

namespace App\Service\Program;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What happened after selection, as numbers a chart can draw: when funds were paid out, who received them (suppliers,
 * businesses, Business Coaches), and the impact the funded businesses reported (jobs created, revenue, ...).
 * It reads the same scope as the analytics page (the applications in view) and never writes.
 */
class ProgramDeliveryAnalytics
{
    private const ROW_CAP = 20000; // rows read per source; far more than one organization's programs hold

    /**
     * @param  Builder  $appIds     query selecting the ids of the applications in view (the analytics scope)
     * @param  list<int>  $programIds
     */
    public function build(Builder $appIds, array $programIds, ?int $roundId = null): array
    {
        $disbursements = DB::table('disbursements as d')
            ->join('program_milestones as m', 'm.id', '=', 'd.milestone_id')
            ->whereIn('m.app_id', $appIds)
            ->limit(self::ROW_CAP)
            ->get(['d.amount', 'd.currency', 'd.status', 'd.recipient_type', 'd.disbursed_at', 'd.created_at']);

        $coachOrders = DB::table('reviewer_orders as o')
            ->whereIn('o.program_id', $programIds ?: [0])
            ->when($roundId, fn ($q) => $q->where('o.round_id', $roundId))
            ->where('o.fee_amount', '>', 0)
            ->where('o.acceptance_status', '!=', 'declined')
            ->limit(self::ROW_CAP)
            ->get(['o.fee_amount', 'o.currency', 'o.payment_status', 'o.order_type', 'o.paid_at', 'o.approved_at', 'o.created_at']);

        // One chart axis needs one currency: the one most money was paid in; others are listed so nothing is hidden
        $paidByCurrency = $disbursements->where('status', 'completed')->groupBy('currency')->map(fn ($rows) => $rows->sum('amount'))
            ->union($coachOrders->where('payment_status', 'completed')->groupBy('currency')->map(fn ($rows) => $rows->sum('fee_amount')))
            ->sortDesc();
        $currency = $paidByCurrency->keys()->first() ?? ($disbursements->first()->currency ?? $coachOrders->first()->currency ?? 'KES');
        $others = $disbursements->pluck('currency')->merge($coachOrders->pluck('currency'))->unique()->reject(fn ($c) => $c === $currency)->values()->all();

        $disbursements = $disbursements->where('currency', $currency);
        $coachOrders = $coachOrders->where('currency', $currency);

        $month = fn ($value) => $value ? Carbon::parse($value)->format('Y-m') : null;
        $isPaid = fn ($status) => $status === 'completed';
        $isFailed = fn ($status) => in_array($status, ['failed', 'reversed'], true);

        // Funds out by month: paid (when it was paid) and still to pay (when it was created)
        $months = [];
        $add = function (?string $key, string $field, float $amount) use (&$months) {
            if ($key) {
                $months[$key] ??= ['month' => $key, 'suppliers_businesses_paid' => 0.0, 'suppliers_businesses_pending' => 0.0, 'coaches_paid' => 0.0, 'coaches_pending' => 0.0];
                $months[$key][$field] = round($months[$key][$field] + $amount, 2);
            }
        };
        foreach ($disbursements as $d) {
            if ($isFailed($d->status)) {
                continue;
            }
            $paid = $isPaid($d->status);
            $add($month($paid ? ($d->disbursed_at ?? $d->created_at) : $d->created_at), $paid ? 'suppliers_businesses_paid' : 'suppliers_businesses_pending', (float) $d->amount);
        }
        foreach ($coachOrders as $o) {
            if ($isFailed($o->payment_status)) {
                continue;
            }
            $paid = $isPaid($o->payment_status);
            $add($month($paid ? ($o->paid_at ?? $o->approved_at ?? $o->created_at) : $o->created_at), $paid ? 'coaches_paid' : 'coaches_pending', (float) $o->fee_amount);
        }
        ksort($months);

        $sum = fn (Collection $rows, string $field) => round((float) $rows->sum($field), 2);
        $byRecipient = fn (string $type, bool $paid) => $sum($disbursements->where('recipient_type', $type)->filter(fn ($d) => $paid ? $isPaid($d->status) : (! $isPaid($d->status) && ! $isFailed($d->status))), 'amount');

        $coachPaid = $coachOrders->filter(fn ($o) => $isPaid($o->payment_status));
        $coachPending = $coachOrders->filter(fn ($o) => ! $isPaid($o->payment_status) && ! $isFailed($o->payment_status));

        return [
            'currency' => $currency,
            'other_currencies' => $others,
            'funds_by_month' => array_values($months),
            'funds_by_recipient' => [
                ['key' => 'suppliers', 'paid' => $byRecipient('supplier', true), 'pending' => $byRecipient('supplier', false)],
                ['key' => 'businesses', 'paid' => $byRecipient('business_owner', true), 'pending' => $byRecipient('business_owner', false)],
                ['key' => 'business_coaches', 'paid' => $sum($coachPaid, 'fee_amount'), 'pending' => $sum($coachPending, 'fee_amount')],
            ],
            'coach_payments' => [
                'paid' => $sum($coachPaid, 'fee_amount'),
                'pending' => $sum($coachPending, 'fee_amount'),
                'count' => $coachOrders->count(),
                'by_type' => [
                    'round_review' => $sum($coachPaid->where('order_type', 'round_review'), 'fee_amount'),
                    'site_visit' => $sum($coachPaid->where('order_type', 'site_visit'), 'fee_amount'),
                ],
            ],
            'impact' => $this->impact($appIds),
        ];
    }

    /**
     * What the funded businesses reported in their monitoring submissions (KPIs such as "Jobs created"). Each business counts
     * once per KPI, with its latest report, so a KPI reported at every checkpoint is not added up several times.
     */
    private function impact(Builder $appIds): array
    {
        $submissions = DB::table('m_e_submissions as s')
            ->whereIn('s.app_id', $appIds)
            ->whereIn('s.status', ['submitted', 'verified'])
            ->whereNotNull('s.kpi_actual_values')
            ->orderByDesc('s.submitted_at')->orderByDesc('s.id')
            ->limit(self::ROW_CAP)
            ->get(['s.app_id', 's.kpi_actual_values']);

        $latest = []; // "<app>|<kpi>" => [label, value]
        foreach ($submissions as $row) {
            foreach ((array) json_decode((string) $row->kpi_actual_values, true) as $item) {
                $label = trim((string) ($item['kpi'] ?? ''));
                $value = $item['value'] ?? null;
                if ($label === '' || ! is_numeric(is_string($value) ? str_replace(',', '', $value) : $value)) {
                    continue;
                }
                $latest[$row->app_id.'|'.mb_strtolower($label)] ??= [$label, (float) (is_string($value) ? str_replace(',', '', $value) : $value), (int) $row->app_id];
            }
        }

        $kpis = collect($latest)->groupBy(fn ($v) => mb_strtolower($v[0]))->map(fn ($rows) => [
            'kpi' => $rows->first()[0],
            'total' => round($rows->sum(fn ($r) => $r[1]), 2),
            'reporting' => $rows->pluck(2)->unique()->count(),
        ])->sortByDesc('total')->values()->take(12)->all();

        return ['kpis' => $kpis, 'businesses_reporting' => collect($latest)->pluck(2)->unique()->count()];
    }
}
