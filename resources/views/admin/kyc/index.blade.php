@extends('admin.layout.mainlayout_admin')

@section('content')
    <div class="page-wrapper">
        <div class="content container-fluid">
            <div class="page-header">
                <div class="row">
                    <div class="col-sm-7 col-auto">
                        <h3 class="page-title">KYC reviews</h3>
                        <ul class="breadcrumb">
                            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                            <li class="breadcrumb-item active">KYC</li>
                        </ul>
                    </div>
                </div>
            </div>

            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger">{{ $errors->first() }}</div>
            @endif

            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link {{ $status === 'submitted' ? 'active' : '' }}" href="{{ route('admin.kyc.index', ['status' => 'submitted']) }}">
                        Pending <span class="badge badge-warning">{{ $counts->submitted ?? 0 }}</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $status === 'verified' ? 'active' : '' }}" href="{{ route('admin.kyc.index', ['status' => 'verified']) }}">
                        Verified <span class="badge badge-success">{{ $counts->verified ?? 0 }}</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $status === 'all' ? 'active' : '' }}" href="{{ route('admin.kyc.index', ['status' => 'all']) }}">All reviewed KYC</a>
                </li>
            </ul>

            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-center mb-0">
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>Type</th>
                                    <th>Submitted</th>
                                    <th>Documents</th>
                                    <th>Status</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($kycVerifications as $kyc)
                                    <tr>
                                        <td>
                                            <strong>{{ trim(($kyc->user?->first_name ?? '').' '.($kyc->user?->last_name ?? '')) ?: ($kyc->user?->display_name ?? 'Deleted user') }}</strong>
                                            <div class="text-muted small">{{ $kyc->user?->email }}</div>
                                        </td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $kyc->verification_type)) }}</td>
                                        <td>{{ $kyc->submitted_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                        <td>{{ $kyc->documents_count }} document(s), {{ $kyc->people_count }} person(s)</td>
                                        <td>
                                            <span class="badge {{ $kyc->status === 'verified' ? 'badge-success' : 'badge-warning' }}">{{ ucfirst($kyc->status) }}</span>
                                        </td>
                                        <td class="text-right">
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#kycDetails{{ $kyc->id }}">Review</button>
                                            @if($kyc->status === 'submitted')
                                                <form action="{{ route('admin.kyc.verify', $kyc) }}" method="POST" class="d-inline" onsubmit="return confirm('Mark this KYC record as verified?');">
                                                    @csrf
                                                    <button class="btn btn-sm btn-success">Verify</button>
                                                </form>
                                                <button type="button" class="btn btn-sm btn-danger" data-toggle="modal" data-target="#rejectKyc{{ $kyc->id }}">Reject</button>
                                            @endif
                                        </td>
                                    </tr>

                                    <div class="modal fade" id="kycDetails{{ $kyc->id }}" tabindex="-1" role="dialog" aria-hidden="true">
                                        <div class="modal-dialog modal-lg" role="document">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">KYC #{{ $kyc->id }} details</h5>
                                                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p><strong>Applicant:</strong> {{ $kyc->user?->email ?? 'Deleted user' }}</p>
                                                    <p><strong>Organization:</strong> {{ $kyc->organization?->name ?? '—' }}</p>
                                                    @php($details = match ($kyc->verification_type) {
                                                        'entrepreneur' => $kyc->entrepreneurDetails,
                                                        'service_provider' => $kyc->serviceProviderDetails,
                                                        'organization' => $kyc->organizationDetails,
                                                    })
                                                    @if($details)
                                                        <h6>Submitted details</h6>
                                                        <dl class="row small">
                                                            @foreach($details->getAttributes() as $field => $value)
                                                                @continue(in_array($field, ['id', 'kyc_verification_id', 'created_at', 'updated_at'], true) || $value === null || $value === '')
                                                                <dt class="col-sm-4">{{ ucfirst(str_replace('_', ' ', $field)) }}</dt>
                                                                <dd class="col-sm-8">{{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</dd>
                                                            @endforeach
                                                        </dl>
                                                    @endif

                                                    <h6>Documents</h6>
                                                    <ul class="list-group mb-3">
                                                        @forelse($kyc->documents as $document)
                                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                                <span>{{ ucfirst(str_replace('_', ' ', $document->document_type)) }} — {{ $document->original_filename }}</span>
                                                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.kyc.documents.download', $document) }}">Download</a>
                                                            </li>
                                                        @empty
                                                            <li class="list-group-item text-muted">No documents uploaded.</li>
                                                        @endforelse
                                                    </ul>

                                                    @if($kyc->people->isNotEmpty())
                                                        <h6>Related people</h6>
                                                        <ul class="list-group">
                                                            @foreach($kyc->people as $person)
                                                                <li class="list-group-item">{{ $person->full_legal_name }} — {{ ucfirst(str_replace('_', ' ', $person->relationship_role)) }}</li>
                                                            @endforeach
                                                        </ul>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    @if($kyc->status === 'submitted')
                                        <div class="modal fade" id="rejectKyc{{ $kyc->id }}" tabindex="-1" role="dialog" aria-hidden="true">
                                            <div class="modal-dialog" role="document">
                                                <form class="modal-content" method="POST" action="{{ route('admin.kyc.reject', $kyc) }}">
                                                    @csrf
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Reject KYC #{{ $kyc->id }}</h5>
                                                        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <label for="rejection_reason{{ $kyc->id }}">Reason</label>
                                                        <textarea class="form-control" id="rejection_reason{{ $kyc->id }}" name="rejection_reason" rows="4" required maxlength="2000"></textarea>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                                        <button class="btn btn-danger">Reject KYC</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    @endif
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted">No {{ $status === 'verified' ? 'verified' : 'pending' }} KYC records found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($kycVerifications->hasPages())
                        <div class="mt-3">{{ $kycVerifications->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
