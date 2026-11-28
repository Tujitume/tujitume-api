@extends('admin.layout.mainlayout_admin')

@section('content')
    @php($r = $demoRequest)
    @php($zone = $r->displayTimezone())
    <div class="page-wrapper">
        <div class="content container-fluid">
            @include('admin.demo_requests._tabs', ['pageTitle' => 'Request #'.$r->id])

            <div class="row">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">{{ $r->requestTypeLabel() }} · {{ $r->org }}</h5>
                            @include('admin.demo_requests._status', ['r' => $r])
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-sm-4">Name</dt><dd class="col-sm-8">{{ $r->name }}</dd>
                                <dt class="col-sm-4">Organization</dt><dd class="col-sm-8">{{ $r->org }}</dd>
                                <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><a href="mailto:{{ $r->email }}">{{ $r->email }}</a></dd>
                                <dt class="col-sm-4">Program type</dt><dd class="col-sm-8">{{ $r->programIndustry?->name ?? '—' }}</dd>
                                <dt class="col-sm-4">Program needs</dt><dd class="col-sm-8" style="white-space:pre-line">{{ $r->notes ?: '—' }}</dd>
                                <dt class="col-sm-4">Requester's time zone</dt><dd class="col-sm-8">{{ $zone }}</dd>
                                <dt class="col-sm-4">Received</dt><dd class="col-sm-8">{{ $r->onRequesterClock($r->created_at) }} · {{ $r->created_at?->utc()->format('Y-m-d H:i') }} UTC</dd>
                                @if($r->reviewed_at)
                                    <dt class="col-sm-4">Reviewed</dt><dd class="col-sm-8">{{ $r->reviewed_at->utc()->format('Y-m-d H:i') }} UTC{{ $r->reviewer ? ' by '.($r->reviewer->name ?: $r->reviewer->email) : '' }}</dd>
                                @endif
                                @if($r->rejection_reason)
                                    <dt class="col-sm-4">Rejection reason</dt><dd class="col-sm-8" style="white-space:pre-line">{{ $r->rejection_reason }}</dd>
                                @endif
                                @if($r->proposal_sent_at)
                                    <dt class="col-sm-4">Proposal sent</dt><dd class="col-sm-8">{{ $r->proposal_sent_at->utc()->format('Y-m-d H:i') }} UTC</dd>
                                @endif
                                @if($r->full_access_requested_at)
                                    <dt class="col-sm-4">Full access requested</dt><dd class="col-sm-8">{{ $r->onRequesterClock($r->full_access_requested_at) }}</dd>
                                @endif
                                @if($convertedUser)
                                    <dt class="col-sm-4">Full account</dt><dd class="col-sm-8">{{ $convertedUser->email }} (user #{{ $convertedUser->id }}){{ $r->converted_at ? ', paid '.$r->converted_at->utc()->format('Y-m-d H:i').' UTC' : ', not paid yet' }}</dd>
                                @endif
                                @if($r->trial_closed_at)
                                    <dt class="col-sm-4">Demo account deleted</dt><dd class="col-sm-8">{{ $r->trial_closed_at->utc()->format('Y-m-d H:i') }} UTC</dd>
                                @endif
                            </dl>
                        </div>
                    </div>

                    @if($trial)
                        <div class="card">
                            <div class="card-header"><h5 class="card-title mb-0">Demo account (temporary)</h5></div>
                            <div class="card-body">
                                <dl class="row">
                                    <dt class="col-sm-4">Sign-in email</dt><dd class="col-sm-8">{{ $trial['user']?->email ?? '—' }} (user #{{ $r->trial_user_id }})</dd>
                                    <dt class="col-sm-4">Password set</dt><dd class="col-sm-8">{{ $trial['password_set'] ? 'Yes, they have signed in' : 'Not yet' }}</dd>
                                    <dt class="col-sm-4">Workspace</dt><dd class="col-sm-8">@if($trial['workspace_url'])<a href="{{ $trial['workspace_url'] }}" target="_blank" rel="noopener">{{ $trial['workspace_url'] }}</a>@else — @endif</dd>
                                    <dt class="col-sm-4">Programs / people</dt><dd class="col-sm-8">{{ $trial['programs'] }} program(s), {{ $trial['people'] }} person(s)</dd>
                                    <dt class="col-sm-4">Started</dt><dd class="col-sm-8">{{ $r->onRequesterClock($r->trial_started_at) }}</dd>
                                    <dt class="col-sm-4">Ends</dt><dd class="col-sm-8"><strong>{{ $r->onRequesterClock($r->trial_expires_at) }}</strong> · {{ $r->trial_expires_at?->utc()->format('Y-m-d H:i') }} UTC
                                        @if($r->trial_expires_at?->isFuture())<span class="text-muted">({{ $r->trial_expires_at->diffForHumans(null, true) }} left)</span>@endif</dd>
                                </dl>
                                <p class="small text-muted mb-3">When the demo ends, the account and everything in it are deleted, unless full access was requested and the sign-up link is still open. Programs in a demo account are never shown to applicants.</p>

                                <div class="d-flex flex-wrap" style="gap:.5rem">
                                    <form method="POST" action="{{ route('admin.demo_requests.open-account', $r) }}" target="_blank" onsubmit="return confirm(@js('Open the demo account in a new tab, signed in as '.($trial['user']?->email ?? 'its owner').'? That browser tab signs in as the demo account for 2 hours; sign out there when you are done.'));">@csrf<button class="btn btn-sm btn-primary">Open demo account</button></form>
                                    <form method="POST" action="{{ route('admin.demo_requests.extend', $r) }}" class="form-inline">
                                        @csrf
                                        <input type="number" name="days" value="7" min="1" max="30" class="form-control form-control-sm mr-1" style="width:70px">
                                        <button class="btn btn-sm btn-outline-primary">Extend (days)</button>
                                    </form>
                                    @unless($trial['password_set'])
                                        <form method="POST" action="{{ route('admin.demo_requests.resend-access', $r) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Resend sign-in link</button></form>
                                    @endunless
                                    <form method="POST" action="{{ route('admin.demo_requests.full-access', $r) }}" onsubmit="return confirm(@js('Email '.($r->email).' a one-time link to create their full account?'));">@csrf<button class="btn btn-sm btn-success">Give full access</button></form>
                                    <form method="POST" action="{{ route('admin.demo_requests.end-trial', $r) }}" onsubmit="return confirm(@js('Delete the demo account and everything in it now? This cannot be undone.'));">@csrf<button class="btn btn-sm btn-danger">End demo now</button></form>
                                </div>
                            </div>
                        </div>
                    @endif

                    @php($pc = config('proposals'))
                    @php($money = fn ($v) => $pc['currency'].' '.number_format((float) $v, 2))
                    @if($r->request_type !== 'demo')
                    <div class="card" id="proposal-terms">
                        <div class="card-header"><h5 class="card-title mb-0">Proposal terms (internal calculator)</h5></div>
                        <div class="card-body">
                            <p class="small text-muted">One way to price every proposal: a one-time implementation fee, a monthly subscription, custom development quoted separately, and a pilot discount that lasts at most {{ $pc['max_discount_months'] }} months and ends on its own. Every concession needs a reason and is kept below. The customer only sees the totals you send. AI credits are not priced here yet.</p>
                            @if($quote)
                                <dl class="row small mb-3">
                                    <dt class="col-sm-5">Subscription</dt><dd class="col-sm-7">{{ $money($quoteTotals['subscription_monthly']) }} / month, billed {{ $quote->billing_period }} ({{ $money($quoteTotals['period_amount']) }})</dd>
                                    @if($quoteTotals['discount_months'])
                                        <dt class="col-sm-5">Pilot price</dt><dd class="col-sm-7">{{ $money($quoteTotals['discounted_monthly']) }} / month for {{ $quoteTotals['discount_months'] }} month(s) ({{ rtrim(rtrim(number_format($quote->discount_percent, 2), '0'), '.') }}% off)</dd>
                                    @endif
                                    <dt class="col-sm-5">Implementation</dt><dd class="col-sm-7">{{ $money($quoteTotals['implementation_fee']) }}</dd>
                                    @if($quoteTotals['custom_development_fee'] > 0)
                                        <dt class="col-sm-5">Custom development</dt><dd class="col-sm-7">{{ $money($quoteTotals['custom_development_fee']) }}</dd>
                                    @endif
                                    <dt class="col-sm-5">First invoice</dt><dd class="col-sm-7"><strong>{{ $money($quoteTotals['first_invoice']) }}</strong></dd>
                                    <dt class="col-sm-5">First 12 months</dt><dd class="col-sm-7">{{ $money($quoteTotals['first_year_total']) }}</dd>
                                    @if($quoteDates['live_at'])
                                        <dt class="col-sm-5">Live since</dt><dd class="col-sm-7">{{ $quoteDates['live_at']->utc()->format('Y-m-d') }}</dd>
                                        @if($quoteDates['discount_ends_at'])
                                            <dt class="col-sm-5">Pilot price ends</dt><dd class="col-sm-7">{{ $quoteDates['discount_ends_at']->utc()->format('Y-m-d') }} <span class="text-muted">(not extended automatically)</span></dd>
                                        @endif
                                        <dt class="col-sm-5">Usage and margin review</dt><dd class="col-sm-7">{{ $quoteDates['review_due_at']->utc()->format('Y-m-d') }}
                                            @if($quoteDates['review_due_at']->isPast())<span class="badge badge-warning">Due</span>@endif</dd>
                                    @else
                                        <dt class="col-sm-5">Review</dt><dd class="col-sm-7">{{ $quote->review_after_days }} days after they go live (first payment)</dd>
                                    @endif
                                </dl>
                            @endif

                            <form method="POST" action="{{ route('admin.demo_requests.quote', $r) }}">
                                @csrf
                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label>Implementation fee (one time, {{ $pc['currency'] }})</label>
                                        <input type="number" step="0.01" min="0" name="implementation_fee" class="form-control" required value="{{ old('implementation_fee', $quote?->implementation_fee) }}">
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label>Subscription per month ({{ $pc['currency'] }})</label>
                                        <input type="number" step="0.01" min="0.01" name="subscription_monthly" class="form-control" required value="{{ old('subscription_monthly', $quote?->subscription_monthly) }}">
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label>Billed</label>
                                        <select name="billing_period" class="form-control">
                                            @foreach(array_keys($pc['billing_periods']) as $period)
                                                <option value="{{ $period }}" @selected(old('billing_period', $quote?->billing_period ?? 'monthly') === $period)>{{ ucfirst($period) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label>Custom development ({{ $pc['currency'] }}, separate)</label>
                                        <input type="number" step="0.01" min="0" name="custom_development_fee" class="form-control" value="{{ old('custom_development_fee', $quote?->custom_development_fee ?? 0) }}">
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label>Pilot discount (%)</label>
                                        <input type="number" step="0.01" min="0" max="{{ $pc['max_discount_percent'] }}" name="discount_percent" class="form-control" value="{{ old('discount_percent', $quote?->discount_percent ?? 0) }}">
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label>For (months, max {{ $pc['max_discount_months'] }})</label>
                                        <input type="number" min="0" max="{{ $pc['max_discount_months'] }}" name="discount_months" class="form-control" value="{{ old('discount_months', $quote?->discount_months ?? 0) }}">
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label>Review after (days)</label>
                                        <input type="number" min="{{ $pc['review_days']['min'] }}" max="{{ $pc['review_days']['max'] }}" name="review_after_days" class="form-control" required value="{{ old('review_after_days', $quote?->review_after_days ?? $pc['review_days']['default']) }}">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Concession reason (needed for any discount, or terms better than the last version)</label>
                                    <textarea name="concession_reason" class="form-control" rows="2" maxlength="2000">{{ old('concession_reason') }}</textarea>
                                </div>
                                <div class="form-group">
                                    <label>Scope (from the scoping call: programs, participants, workflow, integrations)</label>
                                    <textarea name="scope_notes" class="form-control" rows="3" maxlength="5000">{{ old('scope_notes', $quote?->scope_notes) }}</textarea>
                                </div>
                                @if($errors->any())
                                    <div class="alert alert-danger small">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
                                @endif
                                <button class="btn btn-primary">{{ $quote ? 'Save as a new version' : 'Save terms' }}</button>
                            </form>

                            @if($quotes->count() > 0)
                                <h6 class="mt-4">History ({{ $quotes->count() }})</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm small mb-0">
                                        <thead><tr><th>Saved (UTC)</th><th>By</th><th>Per month</th><th>Setup</th><th>Discount</th><th>Reason</th></tr></thead>
                                        <tbody>
                                            @foreach($quotes as $version)
                                                <tr>
                                                    <td class="text-nowrap">{{ $version->created_at->utc()->format('Y-m-d H:i') }}</td>
                                                    <td>{{ $version->admin?->name ?: ($version->admin?->email ?? '—') }}</td>
                                                    <td>{{ number_format($version->subscription_monthly, 2) }}</td>
                                                    <td>{{ number_format($version->implementation_fee, 2) }}</td>
                                                    <td>{{ $version->discount_months ? rtrim(rtrim(number_format($version->discount_percent, 2), '0'), '.').'% × '.$version->discount_months.' mo' : '—' }}</td>
                                                    <td style="white-space:pre-line">{{ $version->concession_reason ?? '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                    @endif

                    @if(!in_array($r->status, ['rejected', 'expired', 'closed', 'converted'], true))
                        <div class="card">
                            <div class="card-header"><h5 class="card-title mb-0">{{ $r->request_type === 'demo' ? 'Email the requester' : 'Proposal / reply' }}</h5></div>
                            <div class="card-body">
                                <p class="small text-muted">Starts from the <a href="{{ route('admin.demo_requests.templates') }}">{{ $r->requestTypeLabel() }} template</a>. Edit it for this request, then send. It goes to {{ $r->email }}.</p>
                                <form method="POST" action="{{ route('admin.demo_requests.proposal', $r) }}">
                                    @csrf
                                    <div class="form-group">
                                        <label>Subject</label>
                                        <input name="proposal_subject" class="form-control" maxlength="255" required value="{{ old('proposal_subject', $r->proposal_subject ?: $template['subject']) }}">
                                    </div>
                                    <div class="form-group">
                                        <label>Message</label>
                                        <textarea id="proposal_body" name="proposal_body" class="form-control" rows="14" required>{{ old('proposal_body', $r->proposal_body ?: $template['body']) }}</textarea>
                                    </div>
                                    @if($quoteSummary)
                                        <button type="button" class="btn btn-outline-secondary mr-2" onclick="var b=document.getElementById('proposal_body');b.value=b.value.replace(/\s*$/,'')+'\n\n'+@js($quoteSummary)+'\n';b.focus();">Put the terms in the message</button>
                                    @endif
                                    <button class="btn btn-primary" onclick="return confirm(@js('Send this email to '.($r->email).'?'));">{{ $r->proposal_sent_at ? 'Send again' : 'Send' }}</button>
                                </form>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="col-lg-5">
                    @if($r->status === 'pending')
                        <div class="card">
                            <div class="card-header"><h5 class="card-title mb-0">Review</h5></div>
                            <div class="card-body">
                                @if($r->request_type === 'demo')
                                    <form method="POST" action="{{ route('admin.demo_requests.approve', $r) }}" class="mb-3" onsubmit="return confirm(@js('Create a '.(\App\Models\DemoRequest::TRIAL_DAYS).'-day demo account for '.($r->email).' and email them the sign-in link?'));">
                                        @csrf
                                        <button class="btn btn-success btn-block">Approve: create {{ \App\Models\DemoRequest::TRIAL_DAYS }}-day demo account</button>
                                    </form>
                                @else
                                    <p class="small text-muted">Answer this request with the proposal / reply form. You can also give full access straight away.</p>
                                    <form method="POST" action="{{ route('admin.demo_requests.full-access', $r) }}" class="mb-3" onsubmit="return confirm(@js('Email '.($r->email).' a one-time link to create their full account?'));">
                                        @csrf
                                        <button class="btn btn-outline-success btn-block">Give full access (send sign-up link)</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('admin.demo_requests.reject', $r) }}">
                                    @csrf
                                    <label for="rejection_reason">Reject with a reason (emailed to them)</label>
                                    <textarea id="rejection_reason" name="rejection_reason" class="form-control mb-2" rows="4" required maxlength="2000">{{ old('rejection_reason') }}</textarea>
                                    <button class="btn btn-danger btn-block" onclick="return confirm(@js('Reject this request and email the reason?'));">Reject</button>
                                </form>
                            </div>
                        </div>
                    @elseif($r->status === 'proposal_sent')
                        <div class="card">
                            <div class="card-body">
                                <form method="POST" action="{{ route('admin.demo_requests.full-access', $r) }}" class="mb-2" onsubmit="return confirm(@js('Email '.($r->email).' a one-time link to create their full account?'));">
                                    @csrf
                                    <button class="btn btn-success btn-block">Give full access (send sign-up link)</button>
                                </form>
                                <form method="POST" action="{{ route('admin.demo_requests.close', $r) }}">@csrf<button class="btn btn-outline-secondary btn-block">Close request</button></form>
                            </div>
                        </div>
                    @endif

                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0">Sign-up links for {{ $r->email }}</h5></div>
                        <div class="card-body">
                            @forelse($invites as $invite)
                                <div class="d-flex justify-content-between small mb-2">
                                    <span>Sent {{ $invite->created_at->utc()->format('Y-m-d H:i') }} UTC · valid to {{ $invite->expires_at->utc()->format('Y-m-d H:i') }} UTC</span>
                                    <span class="badge {{ $invite->state() === 'open' ? 'badge-info' : ($invite->state() === 'used' ? 'badge-success' : 'badge-secondary') }}">{{ ucfirst($invite->state()) }}</span>
                                </div>
                            @empty
                                <p class="text-muted small mb-0">None sent yet.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0">Internal notes</h5></div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.demo_requests.notes', $r) }}">
                                @csrf
                                <textarea name="admin_notes" class="form-control mb-2" rows="4" maxlength="5000">{{ $r->admin_notes }}</textarea>
                                <button class="btn btn-sm btn-outline-primary">Save notes</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
