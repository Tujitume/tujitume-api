<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Amari\AmariUsageLog;
use App\Models\Amari\AmariUserLimit;
use App\Models\Auth\User;
use App\Service\AI\AmariUsage;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Admin › Amari usage: who uses Amari (accounts, their organizations, landing-page visitors), how many requests and
 * tokens, who reached or is near the daily limit; the default limits, and one account's own limit or pause.
 * Amari is free: this is for keeping use in check, not billing. Days and times are UTC.
 */
class AmariUsageAdminController extends Controller
{
    public const TABS = ['users' => 'Accounts', 'organizations' => 'Organizations', 'guests' => 'Visitors (not signed in)', 'activity' => 'Activity'];

    public const PERIODS = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', 'all' => 'All time'];

    public const USER_TYPES = [1 => 'Entrepreneur', 2 => 'Investor', 3 => 'Service provider', 4 => 'Program organization', 5 => 'Capital provider', 6 => 'External Business Coach'];

    public const FLAGS = ['limit_hit' => 'Reached the limit today', 'near' => 'Near the limit today', 'paused' => 'Paused', 'custom' => 'Own limit'];

    private const COUNTED_SQL = "('answered','stopped','error')";

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'users';
        $period = array_key_exists($request->query('period'), self::PERIODS) ? $request->query('period') : '30d';
        $from = $this->periodStart($period);
        $settings = AmariUsage::settings();
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'type' => array_key_exists((int) $request->query('type'), self::USER_TYPES) ? (int) $request->query('type') : null,
            'flag' => array_key_exists($request->query('flag'), self::FLAGS) ? $request->query('flag') : null,
            'org' => is_numeric($request->query('org')) ? (int) $request->query('org') : null,
            'status' => in_array($request->query('status'), AmariUsageLog::STATUSES, true) ? $request->query('status') : null,
            'feature' => array_key_exists((string) $request->query('feature'), AmariUsage::FEATURES) ? $request->query('feature') : null,
            'who' => in_array($request->query('who'), ['accounts', 'visitors'], true) ? $request->query('who') : null,
        ];

        $query = match ($tab) {
            'users' => $this->accounts($from, $filters, $settings),
            'organizations' => $this->organizations($from, $filters),
            'guests' => $this->guests($from, $filters, $settings),
            'activity' => $this->activity($from, $filters),
        };
        $query = $this->sorted($query, $tab, (string) $request->query('sort'));

        if ($request->query('export') === 'csv') {
            return $this->csv($tab, $query, $settings);
        }

        $rows = $query->paginate(25)->withQueryString();
        $summary = $this->summary($from, $settings);
        $byFeature = $this->base($from)->selectRaw('feature, count(*) as requests_count, sum(total_tokens) as tokens_sum')
            ->whereIn('status', AmariUsageLog::COUNTED)->groupBy('feature')->orderByDesc('requests_count')->get();
        $daily = $this->daily(now()->utc()->subDays(13)->startOfDay());
        $organizationName = $filters['org'] ? DB::table('organizations')->where('id', $filters['org'])->value('name') : null;

        return view('admin.amari.index', compact('tab', 'period', 'filters', 'settings', 'rows', 'summary', 'byFeature', 'daily', 'organizationName'));
    }

    public function user(User $user)
    {
        $limit = AmariUsage::limitFor($user);
        $override = AmariUserLimit::where('user_id', $user->id)->first();
        $usedToday = AmariUsage::usedToday($user, null);
        $settings = AmariUsage::settings();
        $own = fn (?Carbon $from) => $this->base($from)->where('user_id', $user->id);

        $stats = collect(['today' => AmariUsage::startOfToday(), '7d' => $this->periodStart('7d'), '30d' => $this->periodStart('30d'), 'all' => null])
            ->map(fn ($from) => $own($from)->selectRaw('sum(case when status in '.self::COUNTED_SQL.' then 1 else 0 end) as requests_count, sum(total_tokens) as tokens_sum, sum(case when status = \'limited\' then 1 else 0 end) as limit_hits')->first());
        $byFeature = $own($this->periodStart('30d'))->selectRaw('feature, count(*) as requests_count, sum(total_tokens) as tokens_sum')
            ->whereIn('status', AmariUsageLog::COUNTED)->groupBy('feature')->orderByDesc('requests_count')->get();
        $daily = $this->daily($this->periodStart('30d'), $user->id);
        $recent = AmariUsageLog::where('user_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get();
        $organization = $user->organization;

        return view('admin.amari.user', compact('user', 'limit', 'override', 'usedToday', 'settings', 'stats', 'byFeature', 'daily', 'recent', 'organization'));
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'user_limit' => ['required', 'integer', 'min:0', 'max:100000'],
            'guest_limit' => ['required', 'integer', 'min:0', 'max:100000'],
            'near_percent' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        AmariUsage::saveSettings((int) $data['user_limit'], (int) $data['guest_limit'], (int) $data['near_percent']);

        return back()->with('success', 'Amari limits saved.');
    }

    public function saveUserLimit(Request $request, User $user)
    {
        $data = $request->validate([
            'limit_mode' => ['required', 'in:default,custom'],
            'daily_limit' => ['nullable', 'required_if:limit_mode,custom', 'integer', 'min:0', 'max:100000'],
            'paused' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $custom = $data['limit_mode'] === 'custom';
        $paused = (bool) ($data['paused'] ?? false);
        $note = trim((string) ($data['note'] ?? '')) ?: null;

        if (! $custom && ! $paused && $note === null) {
            AmariUserLimit::where('user_id', $user->id)->delete(); // back to the default: nothing to keep
        } else {
            AmariUserLimit::updateOrCreate(['user_id' => $user->id], [
                'daily_limit' => $custom ? (int) $data['daily_limit'] : null,
                'paused' => $paused,
                'note' => $note,
                'updated_by_admin_id' => Auth::guard('admin')->id(),
            ]);
        }

        return back()->with('success', 'Amari limit saved for '.trim($user->first_name.' '.$user->last_name).'.');
    }

    // ── lists ─────────────────────────────────────────────────────────────────

    private function base(?Carbon $from): Builder
    {
        return DB::table('amari_usage_logs')->when($from, fn ($q) => $q->where('created_at', '>=', $from));
    }

    /** Aggregates shared by every grouped list: requests that reached the model, tokens, today's use, limit hits */
    private function aggregates(Builder $q): Builder
    {
        $today = AmariUsage::startOfToday()->toDateTimeString();

        return $q->selectRaw(
            'count(*) as attempts_count,'
            .' sum(case when status in '.self::COUNTED_SQL.' then 1 else 0 end) as requests_count,'
            .' sum(total_tokens) as tokens_sum,'
            .' sum(case when status in '.self::COUNTED_SQL.' and created_at >= ? then 1 else 0 end) as today_used,'
            .' sum(case when status = \'limited\' then 1 else 0 end) as limit_hits,'
            .' sum(case when status = \'limited\' and created_at >= ? then 1 else 0 end) as limit_hits_today,'
            .' max(created_at) as last_used_at',
            [$today, $today]
        );
    }

    private function accounts(?Carbon $from, array $f, array $settings): Builder
    {
        $agg = $this->aggregates($this->base($from)->whereNotNull('user_id')->groupBy('user_id'))->addSelect('user_id');

        return DB::query()->fromSub($agg, 'a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('organizations as o', 'o.id', '=', 'u.organization_id')
            ->leftJoin('amari_user_limits as l', 'l.user_id', '=', 'a.user_id')
            ->select('a.*', 'u.first_name', 'u.last_name', 'u.email', 'u.user_type_id', 'u.organization_id', 'o.name as organization_name', 'l.daily_limit', 'l.paused')
            ->when($f['q'] !== '', fn ($q) => $q->where(fn ($w) => $this->like($w, ['u.first_name', 'u.last_name', 'u.email', 'o.name'], $f['q'])))
            ->when($f['type'], fn ($q) => $q->where('u.user_type_id', $f['type']))
            ->when($f['org'], fn ($q) => $q->where('u.organization_id', $f['org']))
            ->when($f['flag'] === 'limit_hit', fn ($q) => $q->where('a.limit_hits_today', '>', 0))
            ->when($f['flag'] === 'paused', fn ($q) => $q->where('l.paused', true))
            ->when($f['flag'] === 'custom', fn ($q) => $q->whereNotNull('l.daily_limit'))
            ->when($f['flag'] === 'near', fn ($q) => $q->whereRaw(
                'coalesce(l.daily_limit, ?) > 0 and a.today_used * 100 >= coalesce(l.daily_limit, ?) * ?',
                [$settings['user_limit'], $settings['user_limit'], $settings['near_percent']]
            ));
    }

    private function organizations(?Carbon $from, array $f): Builder
    {
        $agg = $this->aggregates($this->base($from)->whereNotNull('organization_id')->groupBy('organization_id'))
            ->selectRaw('organization_id, count(distinct user_id) as users_count');

        return DB::query()->fromSub($agg, 'a')
            ->join('organizations as o', 'o.id', '=', 'a.organization_id')
            ->select('a.*', 'o.name as organization_name', 'o.organization_type')
            ->when($f['q'] !== '', fn ($q) => $q->where(fn ($w) => $this->like($w, ['o.name', 'o.legal_name', 'o.email'], $f['q'])))
            ->when($f['flag'] === 'limit_hit', fn ($q) => $q->where('a.limit_hits_today', '>', 0));
    }

    private function guests(?Carbon $from, array $f, array $settings): Builder
    {
        $agg = $this->aggregates($this->base($from)->whereNull('user_id')->whereNotNull('guest_key')->groupBy('guest_key'))
            ->selectRaw('guest_key, min(created_at) as first_seen_at, sum(case when status = \'blocked\' then 1 else 0 end) as blocked_count');

        return DB::query()->fromSub($agg, 'a')->select('a.*')
            ->when($f['q'] !== '', fn ($q) => $q->where('a.guest_key', 'like', $this->escapeLike($f['q']).'%'))
            ->when($f['flag'] === 'limit_hit', fn ($q) => $q->where('a.limit_hits_today', '>', 0))
            ->when($f['flag'] === 'near', fn ($q) => $q->whereRaw('? > 0 and a.today_used * 100 >= ? * ?', [$settings['guest_limit'], $settings['guest_limit'], $settings['near_percent']]));
    }

    private function activity(?Carbon $from, array $f): Builder
    {
        return DB::table('amari_usage_logs as g')
            ->leftJoin('users as u', 'u.id', '=', 'g.user_id')
            ->leftJoin('organizations as o', 'o.id', '=', 'g.organization_id')
            ->when($from, fn ($q) => $q->where('g.created_at', '>=', $from))
            ->select('g.*', 'u.first_name', 'u.last_name', 'u.email', 'o.name as organization_name')
            ->when($f['q'] !== '', fn ($q) => $q->where(fn ($w) => $this->like($w, ['u.first_name', 'u.last_name', 'u.email', 'o.name', 'g.guest_key'], $f['q'])))
            ->when($f['status'], fn ($q) => $q->where('g.status', $f['status']))
            ->when($f['feature'], fn ($q) => $q->where('g.feature', $f['feature']))
            ->when($f['type'], fn ($q) => $q->where('g.user_type_id', $f['type']))
            ->when($f['org'], fn ($q) => $q->where('g.organization_id', $f['org']))
            ->when($f['who'] === 'accounts', fn ($q) => $q->whereNotNull('g.user_id'))
            ->when($f['who'] === 'visitors', fn ($q) => $q->whereNull('g.user_id'));
    }

    private function sorted(Builder $q, string $tab, string $sort): Builder
    {
        if ($tab === 'activity') {
            return $sort === 'tokens' ? $q->orderByDesc('g.total_tokens')->orderByDesc('g.id') : $q->orderByDesc('g.id');
        }

        $column = match ($sort) {
            'tokens' => 'a.tokens_sum',
            'today' => 'a.today_used',
            'recent' => 'a.last_used_at',
            'limit_hits' => 'a.limit_hits',
            default => 'a.requests_count',
        };

        return $q->orderByDesc($column)->orderByDesc('a.last_used_at');
    }

    private function summary(?Carbon $from, array $settings): array
    {
        $row = $this->base($from)->selectRaw(
            'sum(case when status in '.self::COUNTED_SQL.' then 1 else 0 end) as requests_count, sum(total_tokens) as tokens_sum,'
            .' count(distinct user_id) as accounts_count, count(distinct organization_id) as organizations_count,'
            .' sum(case when tokens_estimated = ? then 1 else 0 end) as estimated_count',
            [true]
        )->first();
        $guests = $this->base($from)->whereNull('user_id')->distinct()->count('guest_key');
        $today = $this->base(AmariUsage::startOfToday());

        return [
            'requests' => (int) $row->requests_count,
            'tokens' => (int) $row->tokens_sum,
            'accounts' => (int) $row->accounts_count,
            'organizations' => (int) $row->organizations_count,
            'guests' => $guests,
            'estimated' => (int) $row->estimated_count,
            'today_requests' => (clone $today)->whereIn('status', AmariUsageLog::COUNTED)->count(),
            'today_tokens' => (int) (clone $today)->sum('total_tokens'),
            'limit_hits_today' => (clone $today)->where('status', 'limited')->distinct()->count(DB::raw("coalesce(cast(user_id as char), guest_key)")),
            'paused' => AmariUserLimit::where('paused', true)->count(),
        ];
    }

    /** Requests and tokens per UTC day from $from to today, with empty days filled in */
    private function daily(Carbon $from, ?int $userId = null): array
    {
        $rows = $this->base($from)->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->selectRaw('date(created_at) as usage_day, sum(case when status in '.self::COUNTED_SQL.' then 1 else 0 end) as requests_count, sum(total_tokens) as tokens_sum')
            ->groupBy(DB::raw('date(created_at)'))->get()->keyBy(fn ($r) => substr((string) $r->usage_day, 0, 10));
        $days = [];
        for ($d = $from->copy(); $d->lte(now()->utc()); $d->addDay()) {
            $key = $d->toDateString();
            $days[$key] = ['requests' => (int) ($rows[$key]->requests_count ?? 0), 'tokens' => (int) ($rows[$key]->tokens_sum ?? 0)];
        }

        return $days;
    }

    private function csv(string $tab, Builder $query, array $settings)
    {
        $columns = match ($tab) {
            'users' => ['Name', 'Email', 'Account type', 'Organization', 'Requests', 'Tokens', 'Used today', 'Daily limit', 'Limit hits', 'Paused', 'Last used (UTC)'],
            'organizations' => ['Organization', 'Accounts using Amari', 'Requests', 'Tokens', 'Used today', 'Limit hits', 'Last used (UTC)'],
            'guests' => ['Visitor', 'Requests', 'Tokens', 'Used today', 'Daily limit', 'Limit hits', 'Blocked by prompt check', 'First seen (UTC)', 'Last used (UTC)'],
            'activity' => ['Time (UTC)', 'Who', 'Email', 'Organization', 'Where', 'Feature', 'Status', 'Model', 'Input tokens', 'Output tokens', 'Total tokens', 'Estimated', 'Duration (ms)'],
        };
        $line = fn ($r) => match ($tab) {
            'users' => [trim($r->first_name.' '.$r->last_name), $r->email, self::USER_TYPES[$r->user_type_id] ?? '', $r->organization_name, $r->requests_count, $r->tokens_sum, $r->today_used,
                self::limitLabel($r->daily_limit ?? $settings['user_limit']), $r->limit_hits, $r->paused ? 'Yes' : 'No', $r->last_used_at],
            'organizations' => [$r->organization_name, $r->users_count, $r->requests_count, $r->tokens_sum, $r->today_used, $r->limit_hits, $r->last_used_at],
            'guests' => ['Visitor '.substr($r->guest_key, 0, 8), $r->requests_count, $r->tokens_sum, $r->today_used, self::limitLabel($settings['guest_limit']), $r->limit_hits, $r->blocked_count, $r->first_seen_at, $r->last_used_at],
            'activity' => [$r->created_at, $r->user_id ? trim($r->first_name.' '.$r->last_name) : 'Visitor '.substr((string) $r->guest_key, 0, 8), $r->email, $r->organization_name,
                $r->profile === 'public' ? 'Landing page' : 'App', AmariUsage::FEATURES[$r->feature] ?? $r->feature, ucfirst($r->status), $r->model, $r->input_tokens, $r->output_tokens, $r->total_tokens, $r->tokens_estimated ? 'Yes' : 'No', $r->duration_ms],
        };

        return response()->streamDownload(function () use ($query, $columns, $line) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            $page = 1;
            do { // every matching row, in blocks
                $rows = (clone $query)->forPage($page++, 1000)->get();
                foreach ($rows as $r) {
                    fputcsv($out, $line($r));
                }
            } while ($rows->count() === 1000);
            fclose($out);
        }, 'amari-usage-'.$tab.'-'.now()->utc()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    public static function limitLabel(?int $limit): string
    {
        return ! $limit ? 'No limit' : number_format($limit).' a day';
    }

    private function periodStart(string $period): ?Carbon
    {
        return match ($period) {
            'today' => AmariUsage::startOfToday(),
            '7d' => AmariUsage::startOfToday()->subDays(6),
            '30d' => AmariUsage::startOfToday()->subDays(29),
            '90d' => AmariUsage::startOfToday()->subDays(89),
            default => null,
        };
    }

    private function like($query, array $columns, string $term): void
    {
        $pattern = '%'.$this->escapeLike($term).'%';
        foreach ($columns as $i => $column) {
            $i === 0 ? $query->where($column, 'like', $pattern) : $query->orWhere($column, 'like', $pattern);
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
