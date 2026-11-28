@extends('admin.layout.mainlayout_admin')

@php
    use App\Http\Controllers\Admin\AmariUsageAdminController as Amari;
    use App\Service\AI\AmariUsage;
    $num = fn ($n) => number_format((int) $n);
    $requests = fn ($n) => number_format((int) $n).((int) $n === 1 ? ' request' : ' requests');
    $keep = array_filter(['period' => $period, 'q' => $filters['q'], 'type' => $filters['type'], 'flag' => $filters['flag'], 'org' => $filters['org'],
        'status' => $filters['status'], 'feature' => $filters['feature'], 'who' => $filters['who'], 'sort' => request('sort')]);
    $link = fn (array $change) => route('admin.amari.index', array_filter(array_merge(['tab' => $tab] + $keep, $change), fn ($v) => $v !== null && $v !== ''));
    $maxDaily = max(1, collect($daily)->max('requests'));
    $periodLabel = Amari::PERIODS[$period];
    // "Near the limit": used at least near_percent of a limit that is set
    $near = fn (int $used, int $limit) => $limit > 0 && $used * 100 >= $limit * $settings['near_percent'];
@endphp

@section('content')
    <div class="page-wrapper">
        <div class="content container-fluid">
            @include('admin.amari._header', ['pageTitle' => 'Amari usage'])

            {{-- Overview --}}
            <div class="row">
                @foreach([
                    ['Today', $requests($summary['today_requests']), $num($summary['today_tokens']).' tokens', null],
                    [$periodLabel, $requests($summary['requests']), $num($summary['tokens']).' tokens', null],
                    ['Who used Amari', $num($summary['accounts']).' accounts', $num($summary['organizations']).' organizations · '.$num($summary['guests']).' visitors', null],
                    ['Reached the limit today', $num($summary['limit_hits_today']), $num($summary['paused']).' paused accounts', $link(['tab' => 'users', 'flag' => 'limit_hit', 'page' => null])],
                ] as [$label, $value, $sub, $href])
                    <div class="col-xl-3 col-sm-6 col-12">
                        <div class="card" data-qa="amari-usage-stat">
                            <div class="card-body">
                                <div class="text-muted small">{{ $label }}</div>
                                <h4 class="mb-1 mt-1">@if($href)<a href="{{ $href }}">{{ $value }}</a>@else{{ $value }}@endif</h4>
                                <div class="text-muted small">{{ $sub }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if($summary['estimated'] > 0)
                <p class="text-muted small mt-n2">{{ $num($summary['estimated']) }} {{ $summary['estimated'] === 1 ? 'request has' : 'requests have' }} estimated tokens (the AI sent no count, for example when the person pressed Stop).</p>
            @endif

            <div class="row">
                {{-- Last 14 days --}}
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0">Last 14 days</h5></div>
                        <div class="card-body">
                            @foreach($daily as $day => $d)
                                <div class="d-flex align-items-center mb-1 small">
                                    <span class="text-muted" style="width:86px">{{ \Illuminate\Support\Carbon::parse($day)->format('D j M') }}</span>
                                    <div class="flex-grow-1 mr-2" style="background:#eef2f0;height:10px;border-radius:5px">
                                        <div style="width:{{ round($d['requests'] / $maxDaily * 100) }}%;background:#1f7a4d;height:10px;border-radius:5px"></div>
                                    </div>
                                    <span style="width:150px" class="text-right">{{ $num($d['requests']) }} · {{ $num($d['tokens']) }} tok</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Where the tokens go --}}
                <div class="col-lg-3">
                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0">By feature · {{ $periodLabel }}</h5></div>
                        <div class="card-body">
                            @forelse($byFeature as $f)
                                <div class="d-flex justify-content-between small mb-2">
                                    <span>{{ AmariUsage::FEATURES[$f->feature] ?? $f->feature }}</span>
                                    <span class="text-right">{{ $num($f->requests_count) }}<br><span class="text-muted">{{ $num($f->tokens_sum) }} tokens</span></span>
                                </div>
                            @empty
                                <p class="text-muted small mb-0">No requests in this period.</p>
                            @endforelse
                        </div>
                    </div>
                </div>

                {{-- Default limits --}}
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0">Daily limits</h5></div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.amari.settings') }}" data-qa="amari-limits-form">
                                @csrf
                                <div class="form-group mb-2">
                                    <label class="small mb-1" for="user_limit">Requests a day for each signed-in account</label>
                                    <input id="user_limit" type="number" min="0" max="100000" name="user_limit" class="form-control form-control-sm" value="{{ old('user_limit', $settings['user_limit']) }}" required>
                                </div>
                                <div class="form-group mb-2">
                                    <label class="small mb-1" for="guest_limit">Requests a day for each visitor (landing page, not signed in)</label>
                                    <input id="guest_limit" type="number" min="0" max="100000" name="guest_limit" class="form-control form-control-sm" value="{{ old('guest_limit', $settings['guest_limit']) }}" required>
                                </div>
                                <div class="form-group mb-2">
                                    <label class="small mb-1" for="near_percent">Flag as "near the limit" from (% of the limit)</label>
                                    <input id="near_percent" type="number" min="1" max="100" name="near_percent" class="form-control form-control-sm" value="{{ old('near_percent', $settings['near_percent']) }}" required>
                                </div>
                                <p class="text-muted small mb-2">0 means no limit. One account can get its own limit, or be paused, from its page. Limits reset at midnight UTC.</p>
                                <button class="btn btn-sm btn-primary">Save limits</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Lists --}}
            <ul class="nav nav-tabs mb-3">
                @foreach(Amari::TABS as $key => $label)
                    <li class="nav-item">
                        <a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('admin.amari.index', ['tab' => $key, 'period' => $period]) }}">{{ $label }}</a>
                    </li>
                @endforeach
            </ul>

            <form method="GET" class="form-inline mb-3" data-qa="amari-usage-filters">
                <input type="hidden" name="tab" value="{{ $tab }}">
                @if($filters['org'])<input type="hidden" name="org" value="{{ $filters['org'] }}">@endif
                <select name="period" class="form-control form-control-sm mr-2 mb-2">
                    @foreach(Amari::PERIODS as $value => $label)
                        <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm mr-2 mb-2" style="min-width:240px"
                       placeholder="{{ $tab === 'guests' ? 'Visitor code' : ($tab === 'organizations' ? 'Organization name or email' : 'Name, email or organization') }}">
                @if(in_array($tab, ['users', 'activity'], true))
                    <select name="type" class="form-control form-control-sm mr-2 mb-2">
                        <option value="">All account types</option>
                        @foreach(Amari::USER_TYPES as $value => $label)
                            <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                @endif
                @if($tab !== 'activity')
                    <select name="flag" class="form-control form-control-sm mr-2 mb-2">
                        <option value="">Everyone</option>
                        @foreach(Amari::FLAGS as $value => $label)
                            @continue($tab !== 'users' && in_array($value, ['paused', 'custom'], true))
                            @continue($tab === 'organizations' && $value === 'near')
                            <option value="{{ $value }}" @selected($filters['flag'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <select name="sort" class="form-control form-control-sm mr-2 mb-2">
                        @foreach(['requests' => 'Most requests', 'tokens' => 'Most tokens', 'today' => 'Most used today', 'limit_hits' => 'Most limit hits', 'recent' => 'Last used'] as $value => $label)
                            <option value="{{ $value }}" @selected(request('sort', 'requests') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                @else
                    <select name="who" class="form-control form-control-sm mr-2 mb-2">
                        <option value="">Accounts and visitors</option>
                        <option value="accounts" @selected($filters['who'] === 'accounts')>Accounts only</option>
                        <option value="visitors" @selected($filters['who'] === 'visitors')>Visitors only</option>
                    </select>
                    <select name="feature" class="form-control form-control-sm mr-2 mb-2">
                        <option value="">All features</option>
                        @foreach(AmariUsage::FEATURES as $value => $label)
                            <option value="{{ $value }}" @selected($filters['feature'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <select name="status" class="form-control form-control-sm mr-2 mb-2">
                        <option value="">Any result</option>
                        @foreach(['answered' => 'Answered', 'stopped' => 'Stopped by the person', 'limited' => 'Refused: limit reached', 'paused' => 'Refused: paused', 'blocked' => 'Refused: prompt check', 'error' => 'Failed'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                @endif
                <button class="btn btn-sm btn-outline-primary mr-2 mb-2">Filter</button>
                <a class="btn btn-sm btn-outline-secondary mb-2" href="{{ $link(['export' => 'csv', 'page' => null]) }}" data-qa="amari-usage-export">Export CSV (all matching rows)</a>
            </form>

            @if($filters['org'])
                <p class="small">Showing accounts in <strong>{{ $organizationName ?? 'organization #'.$filters['org'] }}</strong> · <a href="{{ $link(['org' => null, 'page' => null]) }}">show everyone</a></p>
            @endif

            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-center mb-0" data-qa="amari-usage-table">
                            @if($tab === 'users')
                                <thead><tr>
                                    <th>Account</th><th>Organization</th><th class="text-right">Requests</th><th class="text-right">Tokens</th>
                                    <th class="text-right">Today</th><th>Flags</th><th>Last used (UTC)</th><th class="text-right"></th>
                                </tr></thead>
                                <tbody>
                                @forelse($rows as $r)
                                    @php($limit = (int) ($r->daily_limit ?? $settings['user_limit']))
                                    <tr>
                                        <td><strong>{{ trim($r->first_name.' '.$r->last_name) ?: '—' }}</strong>
                                            <div class="text-muted small">{{ $r->email }} · {{ Amari::USER_TYPES[$r->user_type_id] ?? 'Account' }}</div></td>
                                        <td>@if($r->organization_id)<a href="{{ $link(['org' => $r->organization_id, 'page' => null]) }}">{{ $r->organization_name }}</a>@else<span class="text-muted">—</span>@endif</td>
                                        <td class="text-right">{{ $num($r->requests_count) }}</td>
                                        <td class="text-right">{{ $num($r->tokens_sum) }}</td>
                                        <td class="text-right">{{ $num($r->today_used) }}<span class="text-muted small"> / {{ $limit ? $num($limit) : '∞' }}</span></td>
                                        <td>
                                            @if($r->paused)<span class="badge badge-secondary">Paused</span>@endif
                                            @if($r->limit_hits_today > 0)<span class="badge badge-danger">Reached the limit today</span>
                                            @elseif($near((int) $r->today_used, $limit))<span class="badge badge-warning">Near the limit</span>@endif
                                            @if($r->daily_limit !== null)<span class="badge badge-light">Own limit</span>@endif
                                        </td>
                                        <td class="small">{{ \Illuminate\Support\Carbon::parse($r->last_used_at)->format('Y-m-d H:i') }}</td>
                                        <td class="text-right"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.amari.user', $r->user_id) }}">Open</a></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted">No accounts used Amari in this period.</td></tr>
                                @endforelse
                                </tbody>
                            @elseif($tab === 'organizations')
                                <thead><tr>
                                    <th>Organization</th><th class="text-right">Accounts using Amari</th><th class="text-right">Requests</th><th class="text-right">Tokens</th>
                                    <th class="text-right">Today</th><th>Flags</th><th>Last used (UTC)</th><th></th>
                                </tr></thead>
                                <tbody>
                                @forelse($rows as $r)
                                    <tr>
                                        <td><strong>{{ $r->organization_name }}</strong></td>
                                        <td class="text-right">{{ $num($r->users_count) }}</td>
                                        <td class="text-right">{{ $num($r->requests_count) }}</td>
                                        <td class="text-right">{{ $num($r->tokens_sum) }}</td>
                                        <td class="text-right">{{ $num($r->today_used) }}</td>
                                        <td>@if($r->limit_hits_today > 0)<span class="badge badge-danger">Someone reached the limit today</span>@endif</td>
                                        <td class="small">{{ \Illuminate\Support\Carbon::parse($r->last_used_at)->format('Y-m-d H:i') }}</td>
                                        <td class="text-right"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.amari.index', ['tab' => 'users', 'org' => $r->organization_id, 'period' => $period]) }}">Accounts</a></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted">No organization used Amari in this period.</td></tr>
                                @endforelse
                                </tbody>
                            @elseif($tab === 'guests')
                                <thead><tr>
                                    <th>Visitor</th><th class="text-right">Requests</th><th class="text-right">Tokens</th><th class="text-right">Today</th>
                                    <th class="text-right">Blocked by the prompt check</th><th>Flags</th><th>First seen (UTC)</th><th>Last used (UTC)</th>
                                </tr></thead>
                                <tbody>
                                @forelse($rows as $r)
                                    <tr>
                                        <td><strong>Visitor {{ substr($r->guest_key, 0, 8) }}</strong><div class="text-muted small">Landing page, not signed in</div></td>
                                        <td class="text-right">{{ $num($r->requests_count) }}</td>
                                        <td class="text-right">{{ $num($r->tokens_sum) }}</td>
                                        <td class="text-right">{{ $num($r->today_used) }}<span class="text-muted small"> / {{ $settings['guest_limit'] ? $num($settings['guest_limit']) : '∞' }}</span></td>
                                        <td class="text-right">{{ $num($r->blocked_count) }}</td>
                                        <td>
                                            @if($r->limit_hits_today > 0)<span class="badge badge-danger">Reached the limit today</span>
                                            @elseif($near((int) $r->today_used, (int) $settings['guest_limit']))<span class="badge badge-warning">Near the limit</span>@endif
                                        </td>
                                        <td class="small">{{ \Illuminate\Support\Carbon::parse($r->first_seen_at)->format('Y-m-d H:i') }}</td>
                                        <td class="small">{{ \Illuminate\Support\Carbon::parse($r->last_used_at)->format('Y-m-d H:i') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted">No visitors used Amari in this period.</td></tr>
                                @endforelse
                                </tbody>
                            @else
                                <thead><tr>
                                    <th>Time (UTC)</th><th>Who</th><th>Where</th><th>Feature</th><th>Result</th><th class="text-right">Tokens</th><th class="text-right">Took</th>
                                </tr></thead>
                                <tbody>
                                @forelse($rows as $r)
                                    <tr>
                                        <td class="small">{{ \Illuminate\Support\Carbon::parse($r->created_at)->format('Y-m-d H:i:s') }}</td>
                                        <td>
                                            @if($r->user_id)
                                                <a href="{{ route('admin.amari.user', $r->user_id) }}">{{ trim($r->first_name.' '.$r->last_name) ?: $r->email }}</a>
                                                @if($r->organization_name)<div class="text-muted small">{{ $r->organization_name }}</div>@endif
                                            @else
                                                Visitor {{ substr((string) $r->guest_key, 0, 8) }}
                                            @endif
                                        </td>
                                        <td>{{ $r->profile === 'public' ? 'Landing page' : 'App' }}</td>
                                        <td>{{ AmariUsage::FEATURES[$r->feature] ?? $r->feature }}</td>
                                        <td>@include('admin.amari._status', ['status' => $r->status])</td>
                                        <td class="text-right">{{ $num($r->total_tokens) }}@if($r->tokens_estimated)<span class="text-muted small" title="Estimated: the AI sent no count"> est.</span>@endif</td>
                                        <td class="text-right small">{{ $r->duration_ms !== null ? number_format($r->duration_ms / 1000, 1).' s' : '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted">No Amari requests match.</td></tr>
                                @endforelse
                                </tbody>
                            @endif
                        </table>
                    </div>
                    @if($rows->hasPages())
                        <div class="mt-3">{{ $rows->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
