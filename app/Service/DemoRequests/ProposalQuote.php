<?php

namespace App\Service\DemoRequests;

use App\Models\DemoRequest;
use App\Models\DemoRequestQuote;
use Illuminate\Validation\ValidationException;

/**
 * The one internal pricing calculator for tailored proposals (Pilot Pricing and Proposal Flow):
 * a one-time implementation fee, a custom subscription billed monthly / quarterly / annually, custom development
 * quoted separately, and an optional pilot discount that lasts a set number of months (max 6) and ends on its own.
 * Guardrail: a recorded reason for every concession
 * (any discount, or any price lower than the previous version). AI credits are not priced here yet.
 */
class ProposalQuote
{
    public static function rules(): array
    {
        $c = config('proposals');

        return [
            'implementation_fee' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'subscription_monthly' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'billing_period' => ['required', 'in:'.implode(',', array_keys($c['billing_periods']))],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:'.$c['max_discount_percent']],
            'discount_months' => ['nullable', 'integer', 'min:0', 'max:'.$c['max_discount_months']],
            'custom_development_fee' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'review_after_days' => ['required', 'integer', 'min:'.$c['review_days']['min'], 'max:'.$c['review_days']['max']],
            'concession_reason' => ['nullable', 'string', 'max:2000'],
            'scope_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** Totals for a set of terms (a saved quote or the form input). */
    public static function calculate(array|DemoRequestQuote $t): array
    {
        $t = $t instanceof DemoRequestQuote ? $t->toArray() : $t;
        $months = (int) (config('proposals.billing_periods')[$t['billing_period'] ?? 'monthly'] ?? 1);
        $monthly = round((float) ($t['subscription_monthly'] ?? 0), 2);
        $pct = (float) ($t['discount_percent'] ?? 0);
        $discountMonths = $pct > 0 ? (int) ($t['discount_months'] ?? 0) : 0;
        $discounted = round($monthly * (1 - $pct / 100), 2);
        $implementation = round((float) ($t['implementation_fee'] ?? 0), 2);
        $development = round((float) ($t['custom_development_fee'] ?? 0), 2);

        $firstPeriodDiscounted = min($discountMonths, $months);
        $firstPeriod = round($discounted * $firstPeriodDiscounted + $monthly * ($months - $firstPeriodDiscounted), 2);
        $yearDiscounted = min($discountMonths, 12);

        return [
            'currency' => config('proposals.currency'),
            'months_per_period' => $months,
            'subscription_monthly' => $monthly,
            'period_amount' => round($monthly * $months, 2),
            'discounted_monthly' => $discountMonths ? $discounted : $monthly,
            'discount_months' => $discountMonths,
            'implementation_fee' => $implementation,
            'custom_development_fee' => $development,
            'first_invoice' => round($implementation + $development + $firstPeriod, 2),
            'first_year_total' => round($implementation + $development + $discounted * $yearDiscounted + $monthly * (12 - $yearDiscounted), 2),
        ];
    }

    /** Validated input → a new version. Throws ValidationException when a guardrail is broken. */
    public static function save(DemoRequest $request, array $data, int $adminId): DemoRequestQuote
    {
        $pct = (float) ($data['discount_percent'] ?? 0);
        $months = (int) ($data['discount_months'] ?? 0);
        $reason = trim((string) ($data['concession_reason'] ?? ''));
        $errors = [];

        if ($pct > 0 && $months < 1) $errors['discount_months'] = 'A pilot discount needs an end: set how many months it lasts (1–'.config('proposals.max_discount_months').').';
        if ($pct <= 0) $months = 0;

        $previous = $request->quotes()->first();
        $lower = $previous && (
            (float) $data['subscription_monthly'] < $previous->subscription_monthly
            || (float) $data['implementation_fee'] < $previous->implementation_fee
            || (float) ($data['custom_development_fee'] ?? 0) < $previous->custom_development_fee
            || $pct > $previous->discount_percent
            || $months > $previous->discount_months
        );
        if (($pct > 0 || $lower) && $reason === '') {
            $errors['concession_reason'] = $lower
                ? 'These terms are better for the customer than the last version: write down why (every concession is recorded).'
                : 'Write down why this pilot discount is given (every concession is recorded).';
        }
        if ($errors) throw ValidationException::withMessages($errors);

        return $request->quotes()->create([
            'implementation_fee' => round((float) $data['implementation_fee'], 2),
            'subscription_monthly' => round((float) $data['subscription_monthly'], 2),
            'billing_period' => $data['billing_period'],
            'discount_percent' => $pct,
            'discount_months' => $months,
            'custom_development_fee' => round((float) ($data['custom_development_fee'] ?? 0), 2),
            'review_after_days' => (int) $data['review_after_days'],
            'concession_reason' => $reason ?: null,
            'scope_notes' => trim((string) ($data['scope_notes'] ?? '')) ?: null,
            'currency' => config('proposals.currency'),
            'created_by_admin_id' => $adminId,
        ]);
    }

    /** Dates that follow from going live (the first payment): when the pilot discount ends and when to review. */
    public static function milestones(DemoRequest $request, DemoRequestQuote $quote): array
    {
        $live = $request->converted_at;

        return [
            'live_at' => $live,
            'discount_ends_at' => $live && $quote->discount_months > 0 ? $live->copy()->addMonthsNoOverflow($quote->discount_months) : null,
            'review_due_at' => $live ? $live->copy()->addDays($quote->review_after_days) : null,
        ];
    }

    /** Plain-text terms for the proposal email. */
    public static function summary(DemoRequestQuote $quote): string
    {
        $c = self::calculate($quote);
        $money = fn (float $v) => $c['currency'].' '.number_format($v, 2);
        $period = ['monthly' => 'month', 'quarterly' => 'quarter', 'annual' => 'year'][$quote->billing_period] ?? 'month';

        $lines = [
            'Proposed terms',
            '- Implementation (one time): '.$money($c['implementation_fee']),
            '- Subscription: '.$money($c['subscription_monthly']).' a month, billed '.$quote->billing_period.' ('.$money($c['period_amount']).' a '.$period.')',
        ];
        if ($c['discount_months']) {
            $lines[] = '- Pilot pricing: '.rtrim(rtrim(number_format($quote->discount_percent, 2), '0'), '.').'% off the subscription for the first '.$c['discount_months'].' month'.($c['discount_months'] > 1 ? 's' : '').' ('.$money($c['discounted_monthly']).' a month), then the full price';
        }
        if ($c['custom_development_fee'] > 0) $lines[] = '- Custom development (quoted separately): '.$money($c['custom_development_fee']);
        $lines[] = '- First invoice: '.$money($c['first_invoice']);
        $lines[] = '- We review usage and pricing with you '.$quote->review_after_days.' days after you go live.';

        return implode("\n", $lines);
    }
}
