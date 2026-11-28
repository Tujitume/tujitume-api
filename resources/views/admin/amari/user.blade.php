@extends('admin.layout.mainlayout_admin')

@php
    use App\Http\Controllers\Admin\AmariUsageAdminController as Amari;
    use App\Service\AI\AmariUsage;
    $num = fn ($n) => number_format((int) $n);
    $requests = fn ($n) => number_format((int) $n).((int) $n === 1 ? ' request' : ' requests');
    $name = trim($user->first_name.' '.$user->last_name) ?: $user->email;
    $maxDaily = max(1, collect($daily)->max('requests'));
    $custom = old('limit_mode', $override?->daily_limit !== null ? 'custom' : 'default') === 'custom';
@endphp

@section('content')
    <div class="page-wrapper">
        <div class="content container-fluid">
            @include('admin.amari._header', ['pageTitle' => $name])

            <p class="mb-3">
                {{ $user->email }} · {{ Amari::USER_TYPES[$user->user_type_id] ?? 'Account' }}
                @if($organization) · <a href="{{ route('admin.amari.index', ['tab' => 'users', 'org' => $organization->id]) }}">{{ $organization->name }}</a>@endif
            </p>

            <div class="row">
                @foreach(['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'all' => 'All time'] as $key => $label)
                    <div class="col-xl-3 col-sm-6 col-12">
                        <div class="card"><div class="card-body">
                            <div class="text-muted small">{{ $label }}</div>
                            <h4 class="mb-1 mt-1">{{ $requests($stats[$key]->requests_count) }}</h4>
                            <div class="text-muted small">{{ $num($stats[$key]->tokens_sum) }} tokens · {{ $num($stats[$key]->limit_hits) }} refused at the limit</div>
                        </div></div>
                    </div>
                @endforeach
            </div>

            <div class="row">
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0">Limit for this account</h5></div>
                        <div class="card-body">
                            <p class="small mb-2">
                                Today: <strong>{{ $num($usedToday) }}</strong> of {{ $limit['limit'] ? $num($limit['limit']) : 'no limit' }}
                                @if($limit['paused'])<span class="badge badge-secondary ml-1">Paused</span>@endif
                            </p>
                            <form method="POST" action="{{ route('admin.amari.user.limit', $user) }}" data-qa="amari-user-limit-form">
                                @csrf
                                <div class="custom-control custom-radio">
                                    <input type="radio" id="mode_default" name="limit_mode" value="default" class="custom-control-input" @checked(! $custom)>
                                    <label class="custom-control-label small" for="mode_default">Default for signed-in accounts ({{ Amari::limitLabel($settings['user_limit']) }})</label>
                                </div>
                                <div class="custom-control custom-radio mb-2">
                                    <input type="radio" id="mode_custom" name="limit_mode" value="custom" class="custom-control-input" @checked($custom)>
                                    <label class="custom-control-label small" for="mode_custom">Own limit (requests a day, 0 = no limit)</label>
                                </div>
                                <input type="number" min="0" max="100000" name="daily_limit" class="form-control form-control-sm mb-2" value="{{ old('daily_limit', $override?->daily_limit) }}" placeholder="e.g. 50">
                                <div class="custom-control custom-checkbox mb-2">
                                    <input type="hidden" name="paused" value="0">
                                    <input type="checkbox" id="paused" name="paused" value="1" class="custom-control-input" @checked(old('paused', $override?->paused))>
                                    <label class="custom-control-label small" for="paused">Pause Amari for this account</label>
                                </div>
                                <textarea name="note" rows="2" maxlength="500" class="form-control form-control-sm mb-2" placeholder="Note (why, for the team)">{{ old('note', $override?->note) }}</textarea>
                                @if($override?->updated_at)<p class="text-muted small mb-2">Last changed {{ $override->updated_at->utc()->format('Y-m-d H:i') }} UTC</p>@endif
                                <button class="btn btn-sm btn-primary">Save</button>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0">By feature · last 30 days</h5></div>
                        <div class="card-body">
                            @forelse($byFeature as $f)
                                <div class="d-flex justify-content-between small mb-2">
                                    <span>{{ AmariUsage::FEATURES[$f->feature] ?? $f->feature }}</span>
                                    <span class="text-right">{{ $num($f->requests_count) }} · <span class="text-muted">{{ $num($f->tokens_sum) }} tokens</span></span>
                                </div>
                            @empty
                                <p class="text-muted small mb-0">No requests in the last 30 days.</p>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0">Last 30 days</h5></div>
                        <div class="card-body">
                            @foreach($daily as $day => $d)
                                <div class="d-flex align-items-center mb-1 small">
                                    <span class="text-muted" style="width:86px">{{ \Illuminate\Support\Carbon::parse($day)->format('D j M') }}</span>
                                    <div class="flex-grow-1 mr-2" style="background:#eef2f0;height:8px;border-radius:4px">
                                        <div style="width:{{ round($d['requests'] / $maxDaily * 100) }}%;background:#1f7a4d;height:8px;border-radius:4px"></div>
                                    </div>
                                    <span style="width:150px" class="text-right">{{ $num($d['requests']) }} · {{ $num($d['tokens']) }} tok</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Latest 50 requests</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-center mb-0" data-qa="amari-user-requests">
                            <thead><tr><th>Time (UTC)</th><th>Where</th><th>Feature</th><th>Result</th><th>Model</th><th class="text-right">In</th><th class="text-right">Out</th><th class="text-right">Took</th></tr></thead>
                            <tbody>
                            @forelse($recent as $r)
                                <tr>
                                    <td class="small">{{ $r->created_at?->utc()->format('Y-m-d H:i:s') }}</td>
                                    <td>{{ $r->profile === 'public' ? 'Landing page' : 'App' }}</td>
                                    <td>{{ AmariUsage::FEATURES[$r->feature] ?? $r->feature }}</td>
                                    <td>@include('admin.amari._status', ['status' => $r->status])</td>
                                    <td class="small text-muted">{{ $r->model ?? '—' }}</td>
                                    <td class="text-right">{{ $num($r->input_tokens) }}</td>
                                    <td class="text-right">{{ $num($r->output_tokens) }}@if($r->tokens_estimated)<span class="text-muted small"> est.</span>@endif</td>
                                    <td class="text-right small">{{ $r->duration_ms !== null ? number_format($r->duration_ms / 1000, 1).' s' : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-muted">This account has not used Amari yet.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
