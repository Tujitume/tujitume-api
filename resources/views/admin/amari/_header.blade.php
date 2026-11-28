{{-- Header and flash messages for the Amari usage pages. Expects $pageTitle. --}}
<div class="page-header">
    <div class="row">
        <div class="col-sm-8 col-auto">
            <h3 class="page-title">{{ $pageTitle }}</h3>
            <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.amari.index') }}">Amari usage</a></li>
                @if($pageTitle !== 'Amari usage')
                    <li class="breadcrumb-item active">{{ $pageTitle }}</li>
                @endif
            </ul>
        </div>
        <div class="col-sm-4 col text-right text-muted small pt-2">
            Amari is free for everyone. These numbers are for keeping use in check; nothing is billed. Days are UTC.
        </div>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif
