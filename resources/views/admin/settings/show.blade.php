@extends('../admin.layout.mainlayout_admin')
@section('content')
<!-- Page Wrapper -->
<div class="container">
    <h1 class="mb-4">View Setting</h1>

    <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="card-title">{{ $setting->key }}</h5>
            <p class="card-text"><strong>Value:</strong> {{ $setting->value }}</p>

            <a href="{{ route('settings.edit', $setting->id) }}" class="btn btn-primary">
                Edit Setting
            </a>
            <a href="{{ route('settings.index') }}" class="btn btn-secondary">
                Back to Settings
            </a>
        </div>
    </div>
</div>

	<script type="text/javascript">
		new DataTable('#example');
	</script>


@endsection
