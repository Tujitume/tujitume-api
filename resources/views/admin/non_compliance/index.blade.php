@extends('admin.layout.mainlayout_admin')

@section('content')
    <div class="page-wrapper">
        <div class="content container-fluid">
            <div class="page-header">
                <h3 class="page-title">Milestone non-compliance</h3>
                <p class="text-muted mb-0">Business milestones that missed their deadline. A platform dispute (investors froze or disputed the milestone, or nobody voted) waits for Tujitume here; left alone it sanctions the business after {{ $pidDays }} days.</p>
            </div>

            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            @php($tabLabels = ['disputes' => 'Platform disputes', 'open' => 'Owner / investor stage', 'closed' => 'Closed', 'all' => 'All'])
            @php($stageLabels = ['response_window' => 'Owner has 72 hours', 'ipm' => 'Investors voting', 'pid' => 'Platform dispute', 'resolved' => 'Resolved', 'sanctioned' => 'Sanctioned'])
            <ul class="nav nav-tabs mb-3">
                @foreach($tabLabels as $key => $label)
                    <li class="nav-item">
                        <a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('admin.non_compliance.index', ['tab' => $key]) }}">
                            {{ $label }} <span class="badge {{ $key === 'disputes' ? 'badge-warning' : 'badge-light' }}">{{ $counts[$key] ?? 0 }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-center mb-0">
                            <thead>
                                <tr>
                                    <th>Business / milestone</th>
                                    <th>Owner</th>
                                    <th>Stage</th>
                                    <th>Owner's answer</th>
                                    <th>Investors decided</th>
                                    <th>Dispute opened (UTC)</th>
                                    <th class="text-right">Settle</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($cases as $case)
                                    @php($owner = $case->milestone?->listing?->owner)
                                    <tr>
                                        <td>
                                            <strong>{{ $case->milestone?->listing?->name ?? '—' }}</strong>
                                            <div class="text-muted small">{{ $case->milestone?->title ?? 'Milestone #'.$case->milestone_id }} · {{ $case->trigger_reason }}</div>
                                        </td>
                                        <td>
                                            {{ $owner ? trim($owner->first_name.' '.$owner->last_name) : '—' }}
                                            <div class="text-muted small">{{ $owner?->email }}</div>
                                        </td>
                                        <td><span class="badge {{ $case->stage === 'pid' ? 'badge-warning' : ($case->stage === 'sanctioned' ? 'badge-danger' : 'badge-light') }}">{{ $stageLabels[$case->stage] ?? $case->stage }}</span></td>
                                        <td>
                                            {{ $case->owner_response_type ? str_replace('_', ' ', $case->owner_response_type) : 'No answer' }}
                                            @if($case->owner_response_note)<div class="text-muted small">{{ \Illuminate\Support\Str::limit($case->owner_response_note, 160) }}</div>@endif
                                        </td>
                                        <td>{{ $case->investor_decision ?: ($case->stage === 'pid' ? 'No vote' : '—') }}</td>
                                        <td>{{ $case->pid_started_at ? \Illuminate\Support\Carbon::parse($case->pid_started_at)->utc()->format('Y-m-d H:i') : '—' }}</td>
                                        <td class="text-right text-nowrap">
                                            @if($case->stage === 'pid' && ! $case->sanctioned)
                                                <form method="POST" action="{{ route('admin.non_compliance.resolve', $case->id) }}" class="d-inline"
                                                      onsubmit="return confirm('Unlock this milestone with 7 more days? The owner and investors are told.')">
                                                    @csrf
                                                    <input type="hidden" name="outcome" value="continue">
                                                    <button class="btn btn-sm btn-outline-success">Let it continue</button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.non_compliance.resolve', $case->id) }}" class="d-inline"
                                                      onsubmit="return confirm('Sanction this business now? Further milestones stay locked; the owner and investors are told.')">
                                                    @csrf
                                                    <input type="hidden" name="outcome" value="sanction">
                                                    <button class="btn btn-sm btn-outline-danger">Sanction now</button>
                                                </form>
                                            @else
                                                <span class="text-muted small">{{ $case->resolution_result ?? '—' }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-4">Nothing here.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $cases->links() }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection
