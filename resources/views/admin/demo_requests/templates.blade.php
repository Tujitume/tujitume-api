@extends('admin.layout.mainlayout_admin')

@section('content')
    <div class="page-wrapper">
        <div class="content container-fluid">
            @include('admin.demo_requests._tabs', ['pageTitle' => 'Proposal templates'])

            <p class="text-muted">Each request starts its reply from one of these. You can still edit the text on the request before sending. Placeholders: <code>{name}</code> (first name), <code>{org}</code>, <code>{program_type}</code>, <code>{scope}</code> and <code>{terms}</code> (filled from the proposal terms you save on each request: prices, pilot discount, first invoice).</p>

            @foreach(['proposal' => 'Request a Proposal', 'pilot' => 'Start a Pilot', 'info' => 'General Inquiry'] as $type => $label)
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">{{ $label }}</h5></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.demo_requests.templates.save', $type) }}">
                            @csrf
                            <div class="form-group">
                                <label>Subject</label>
                                <input name="subject" class="form-control" maxlength="255" required value="{{ $templates[$type]['subject'] }}">
                            </div>
                            <div class="form-group">
                                <label>Message</label>
                                <textarea name="body" class="form-control" rows="12" required>{{ $templates[$type]['body'] }}</textarea>
                            </div>
                            <button class="btn btn-primary">Save template</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endsection
