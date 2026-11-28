@extends('admin.layout.mainlayout_admin')

@section('content')
    <div class="page-wrapper">
        <div class="content container-fluid">
            @include('admin.demo_requests._tabs', ['pageTitle' => 'Sign-up links'])

            <p class="text-muted">Program provider accounts can only be created from a one-time link: the sign-up page is not public. Each link works for one email address, once, for {{ \App\Models\ProgramRegistrationInvite::VALID_DAYS }} days. After signing up they pick a plan and pay, then get the "workspace ready" email.</p>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Send a sign-up link</h5></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.demo_requests.invites.create') }}" class="form-row">
                        @csrf
                        <div class="col-md-5 mb-2"><input type="email" name="email" class="form-control" placeholder="Email" required maxlength="191" value="{{ old('email') }}"></div>
                        <div class="col-md-4 mb-2"><input name="name" class="form-control" placeholder="Name (optional)" maxlength="150" value="{{ old('name') }}"></div>
                        <div class="col-md-3 mb-2"><button class="btn btn-primary btn-block">Email the link</button></div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-center mb-0">
                            <thead>
                                <tr><th>Email</th><th>From request</th><th>Sent (UTC)</th><th>Valid to (UTC)</th><th>State</th><th class="text-right"></th></tr>
                            </thead>
                            <tbody>
                                @forelse($invites as $invite)
                                    <tr>
                                        <td>{{ $invite->email }}@if($invite->name)<div class="small text-muted">{{ $invite->name }}</div>@endif</td>
                                        <td>@if($invite->demo_request_id)<a href="{{ route('admin.demo_requests.show', $invite->demo_request_id) }}">#{{ $invite->demo_request_id }}</a>@else — @endif</td>
                                        <td>{{ $invite->created_at->utc()->format('Y-m-d H:i') }}</td>
                                        <td>{{ $invite->expires_at->utc()->format('Y-m-d H:i') }}</td>
                                        <td><span class="badge {{ $invite->state() === 'open' ? 'badge-info' : ($invite->state() === 'used' ? 'badge-success' : 'badge-secondary') }}">{{ ucfirst($invite->state()) }}</span></td>
                                        <td class="text-right">
                                            @if($invite->state() === 'open')
                                                <form method="POST" action="{{ route('admin.demo_requests.invites.revoke', $invite) }}" onsubmit="return confirm('Stop this link from working?');">@csrf<button class="btn btn-sm btn-outline-danger">Revoke</button></form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted">No links sent yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($invites->hasPages())<div class="mt-3">{{ $invites->links() }}</div>@endif
                </div>
            </div>
        </div>
    </div>
@endsection
