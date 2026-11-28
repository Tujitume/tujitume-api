@extends('admin.layout.mainlayout_admin')

@section('content')
    <div class="page-wrapper">
        <div class="content container-fluid">
            @include('admin.demo_requests._tabs', ['pageTitle' => 'Demo Requests'])

            @php($tabLabels = ['pending' => 'Pending', 'trials' => 'Demo accounts', 'answered' => 'Proposal sent', 'converted' => 'Full access', 'closed' => 'Closed', 'all' => 'All'])
            <ul class="nav nav-tabs mb-3">
                @foreach($tabLabels as $key => $label)
                    <li class="nav-item">
                        <a class="nav-link {{ $status === $key ? 'active' : '' }}" href="{{ route('admin.demo_requests.index', array_filter(['status' => $key, 'type' => $type, 'q' => $search])) }}">
                            {{ $label }} <span class="badge {{ $key === 'pending' ? 'badge-warning' : 'badge-light' }}">{{ $counts[$key] ?? 0 }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <form method="GET" class="form-inline mb-3">
                <input type="hidden" name="status" value="{{ $status }}">
                <input type="search" name="q" value="{{ $search }}" class="form-control form-control-sm mr-2" placeholder="Search name, organization, email" style="min-width:260px">
                <select name="type" class="form-control form-control-sm mr-2">
                    <option value="">All request types</option>
                    @foreach(['demo' => 'Book a Demo', 'proposal' => 'Request a Proposal', 'pilot' => 'Start a Pilot', 'info' => 'General Inquiry'] as $value => $label)
                        <option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-outline-primary">Filter</button>
            </form>

            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-center mb-0">
                            <thead>
                                <tr>
                                    <th>Requester</th>
                                    <th>Request</th>
                                    <th>Program type</th>
                                    <th>Received (UTC)</th>
                                    <th>Status</th>
                                    <th>Demo ends (UTC)</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($demoRequests as $r)
                                    <tr>
                                        <td>
                                            <strong>{{ $r->name }}</strong> · {{ $r->org }}
                                            <div class="text-muted small">{{ $r->email }}</div>
                                        </td>
                                        <td>{{ $r->requestTypeLabel() }}</td>
                                        <td>{{ $r->programIndustry?->name ?? '—' }}</td>
                                        <td>{{ $r->created_at?->utc()->format('Y-m-d H:i') }}</td>
                                        <td>@include('admin.demo_requests._status', ['r' => $r])</td>
                                        <td>{{ $r->hasTrial() ? $r->trial_expires_at?->utc()->format('Y-m-d H:i') : '—' }}</td>
                                        <td class="text-right">
                                            <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.demo_requests.show', $r) }}">Open</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted">No requests here.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($demoRequests->hasPages())
                        <div class="mt-3">{{ $demoRequests->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
