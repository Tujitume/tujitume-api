{{-- Header, flash messages and the section tabs shared by the Demo Requests pages. Expects $pageTitle. --}}
<div class="page-header">
    <div class="row">
        <div class="col-sm-7 col-auto">
            <h3 class="page-title">{{ $pageTitle }}</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.demo_requests.index') }}">Demo Requests</a></li>
                @if($pageTitle !== 'Demo Requests')
                    <li class="breadcrumb-item active">{{ $pageTitle }}</li>
                @endif
            </ul>
        </div>
        <div class="col-sm-5 col text-right">
            <a href="{{ route('admin.demo_requests.index') }}" class="btn btn-sm {{ Request::is('admin/demo-requests') ? 'btn-primary' : 'btn-outline-primary' }}">Requests</a>
            <a href="{{ route('admin.demo_requests.templates') }}" class="btn btn-sm {{ Request::is('admin/demo-requests/templates') ? 'btn-primary' : 'btn-outline-primary' }}">Proposal templates</a>
            <a href="{{ route('admin.demo_requests.invites') }}" class="btn btn-sm {{ Request::is('admin/demo-requests/invites') ? 'btn-primary' : 'btn-outline-primary' }}">Sign-up links</a>
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
